/* global Craft, Garnish, $, tus */

(function () {
  'use strict';

  if (typeof Craft.BunnyMate === 'undefined') {
    Craft.BunnyMate = {};
  }

  /**
   * Loads the TUS client on demand, once.
   *
   * It's 86KB and most control panel screens never start an upload, so it isn't part of
   * the asset bundle's own JS.
   *
   * @returns {Promise}
   */
  Craft.BunnyMate.loadTus = function () {
    if (typeof tus !== 'undefined') {
      return Promise.resolve();
    }
    if (Craft.BunnyMate._tusPromise) {
      return Craft.BunnyMate._tusPromise;
    }
    Craft.BunnyMate._tusPromise = new Promise(function (resolve, reject) {
      if (!Craft.BunnyMate.tusUrl) {
        Craft.BunnyMate._tusPromise = null;
        return Promise.reject(new Error('The upload client couldn’t be located.'));
      }
      var script = document.createElement('script');
      script.src = Craft.BunnyMate.tusUrl;
      script.onload = resolve;
      script.onerror = function () {
        Craft.BunnyMate._tusPromise = null;
        reject(new Error('Couldn’t load the upload client.'));
      };
      document.head.appendChild(script);
    });
    return Craft.BunnyMate._tusPromise;
  };

  /**
   * Uploads video files straight to Bunny Stream over TUS.
   *
   * Craft only ever sees a request for upload credentials and a request to refresh
   * metadata afterwards, so PHP's upload limits never come into it.
   */

  /**
   * Drop-in replacement for Craft's asset uploader, for volumes backed by Bunny Stream.
   *
   * Video files are sent straight to Bunny over TUS, so they never pass through PHP.
   * Everything else, and every folder that isn't set up for Bunny Stream, falls through to
   * Craft's own uploader untouched.
   *
   * Craft dispatches to this class via Craft.registerUploaderClass(), keyed on the
   * filesystem class of the volume being uploaded to.
   */
  Craft.BunnyMate.StreamUploader = Craft.Uploader.extend({
    _folderInfo: null,
    _folderInfoPromises: null,
    _streamUploads: null,

    init: function ($element, settings) {
      this._folderInfo = {};
      this._folderInfoPromises = {};
      this._streamUploads = [];
      this.base($element, settings);
    },

    /**
     * Craft calls this whenever the target folder changes, which gives us a window to find
     * out whether the new folder is a Bunny Stream folder before any file arrives.
     */
    setParams: function (params) {
      this.base(params);
      if (this.targetKey()) {
        this.loadFolderInfo();
      }
    },

    /**
     * Returns the parameters identifying where an upload is bound for.
     *
     * The asset index sets a folderId. An Assets field's own upload button sets a fieldId
     * instead, with an elementId when the element has been saved, and the server works the
     * folder out from the field's upload location.
     *
     * @returns {Object|null}
     */
    targetParams: function () {
      var data = this.formData || {};
      if (data.folderId) {
        return {folderId: data.folderId};
      }
      if (data.fieldId) {
        var params = {fieldId: data.fieldId};
        if (data.elementId) {
          params.elementId = data.elementId;
        }
        if (data.siteId) {
          params.siteId = data.siteId;
        }
        return params;
      }
      return null;
    },

    /**
     * Returns a cache key for the current target, or null if there isn't one.
     *
     * @returns {String|null}
     */
    targetKey: function () {
      var params = this.targetParams();
      return params ? JSON.stringify(params) : null;
    },

    /**
     * @returns {Promise}
     */
    loadFolderInfo: function () {
      var self = this;
      var params = this.targetParams();
      var key = this.targetKey();

      if (!key) {
        return Promise.resolve({stream: false});
      }
      if (typeof this._folderInfoPromises[key] !== 'undefined') {
        return this._folderInfoPromises[key];
      }

      this._folderInfoPromises[key] = Craft.sendActionRequest(
        'GET',
        'bunnymate/upload/folder-info',
        {params: params}
      )
        .then(function (response) {
          self._folderInfo[key] = response.data;
          return response.data;
        })
        .catch(function () {
          // Treat an unknown target as a regular one, so uploads still work
          self._folderInfo[key] = {stream: false};
          return self._folderInfo[key];
        });

      return this._folderInfoPromises[key];
    },

    /**
     * @param {File} file
     * @returns {Boolean}
     */
    shouldStream: function (file) {
      var key = this.targetKey();
      if (!key) {
        return false;
      }
      var info = this._folderInfo[key];
      // Not resolved yet: let Craft handle it. The file still reaches Bunny via the
      // server-side fetch fallback, just without bypassing PHP's upload limits.
      if (!info || !info.stream) {
        return false;
      }
      var match = file.name.match(/\.([a-z0-9_]+)$/i);
      if (!match) {
        return false;
      }
      return (info.extensions || []).indexOf(match[1].toLowerCase()) !== -1;
    },

    /**
     * @inheritdoc
     */
    onFileAdd: function (event, data) {
      var file = data.files && data.files[0];

      if (!file || !this.shouldStream(file)) {
        return this.base(event, data);
      }

      event.stopPropagation();
      this.uploadToStream(file);

      // Keep Craft's uploader from submitting this file over XHR
      return false;
    },

    /**
     * @param {File} file
     */
    uploadToStream: function (file) {
      var self = this;

      this._inProgressCounter++;
      // Craft's progress bar is shared with every other uploader on the page, so it only wears
      // Bunny's colours while one of ours is actually running
      Garnish.$bod.addClass('bunnymate-uploading');
      this.$element.trigger('fileuploadstart');

      // Held so a failure after this point can tidy up the asset and the empty video that
      // preparing already created
      var credentials = null;

      Craft.sendActionRequest('POST', 'bunnymate/upload/prepare', {
        data: $.extend({filename: file.name}, this.targetParams()),
      })
        .then(function (response) {
          credentials = response.data;
          return Craft.BunnyMate.loadTus();
        })
        .then(function () {
          self.startTusUpload(file, credentials);
        })
        .catch(function (error) {
          // Without this, anything failing between preparing and the first byte leaves an
          // asset behind pointing at a Bunny video that never received anything
          if (credentials) {
            self.abortUpload(credentials.assetId);
          }
          // eslint-disable-next-line no-console
          console.error('[BunnyMate] upload failed', error);
          self.failUpload(
            file,
            (error.response && error.response.data && error.response.data.message) ||
              (error && error.message) ||
              Craft.t('bunnymate', 'Couldn’t start the upload.')
          );
        });
    },

    /**
     * @param {File} file
     * @param {Object} credentials
     */
    startTusUpload: function (file, credentials) {
      var self = this;

      var upload = new tus.Upload(file, {
        endpoint: credentials.endpoint,
        retryDelays: [0, 3000, 5000, 10000, 20000],
        headers: {
          AuthorizationSignature: credentials.signature,
          AuthorizationExpire: credentials.expires,
          VideoId: credentials.videoId,
          LibraryId: credentials.libraryId,
        },
        metadata: {
          filetype: file.type,
          title: credentials.filename,
        },
        // Don't leave fingerprints behind for uploads that finished
        removeFingerprintOnSuccess: true,
        onProgress: function (bytesUploaded, bytesTotal) {
          // Craft's index reads loaded/total off the progress event
          self.$element.trigger('fileuploadprogressall', [
            {
              loaded: bytesUploaded,
              total: bytesTotal,
            },
          ]);
        },
        onSuccess: function () {
          self.finishUpload(credentials);
        },
        onError: function (error) {
          self.abortUpload(credentials.assetId);
          self.failUpload(file, error.message || Craft.t('bunnymate', 'Upload failed.'));
        },
      });

      this._streamUploads.push(upload);

      // Deliberately not resuming a previous upload. tus-js-client fingerprints a file by its
      // name, size and date, so uploading the same file again finds the URL from last time,
      // and every prepare creates a new Bunny video, so that URL belongs to a different one.
      // Resuming would either target the wrong video or, once the old upload has expired, HEAD
      // a dead URL and fail. Retries within this upload are handled by retryDelays.
      upload.start();
    },

    /**
     * @param {Object} credentials
     */
    finishUpload: function (credentials) {
      var self = this;

      Craft.sendActionRequest('POST', 'bunnymate/upload/complete', {
        data: {assetId: credentials.assetId},
      })
        .catch(function () {
          // The bytes are on Bunny either way; the webhook will catch the status up
        })
        .then(function () {
          // Triggered through jQuery, not as a native CustomEvent. Craft's handler takes the
          // detail off a CustomEvent or `result` off the data argument, and a native event
          // reaches its jQuery listener wrapped in a jQuery.Event, so the CustomEvent branch
          // is never the one that runs and it reads `result` off an argument that isn't there.
          self.$element.trigger('fileuploaddone', [
            {
              result: {
                assetId: credentials.assetId,
                filename: credentials.filename,
              },
            },
          ]);
          self.endUpload();
        });
    },

    /**
     * @param {File} file
     * @param {String} message
     */
    failUpload: function (file, message) {
      Craft.cp.displayError(message);
      // Craft's handler calls response() on the data argument, so it has to be there
      this.$element.trigger('fileuploadfail', [
        {
          response: function () {
            return null;
          },
        },
      ]);
      this.endUpload();
    },

    /**
     * @param {Number} assetId
     */
    abortUpload: function (assetId) {
      if (!assetId) {
        return;
      }
      Craft.sendActionRequest('POST', 'bunnymate/upload/abort', {
        data: {assetId: assetId},
      }).catch(function () {
        // Nothing useful to do here
      });
    },

    endUpload: function () {
      this._inProgressCounter = Math.max(0, this._inProgressCounter - 1);
      if (this._inProgressCounter === 0) {
        Garnish.$bod.removeClass('bunnymate-uploading');
      }
      this.$element.trigger('fileuploadalways');
    },

    /**
     * @inheritdoc
     *
     * Craft's uploader counts in-flight XHR uploads; ours also has TUS uploads in the air.
     */
    getInProgress: function () {
      return this.base() + this._inProgressCounter;
    },

    destroy: function () {
      Garnish.$bod.removeClass('bunnymate-uploading');
      for (var i = 0; i < this._streamUploads.length; i++) {
        try {
          this._streamUploads[i].abort();
        } catch (e) {
          // Already finished or never started
        }
      }
      this._streamUploads = [];
      this.base();
    },
  });

  /**
   * Registers the uploader for every filesystem class used by a Bunny Stream volume.
   *
   * @param {Array} fsTypes
   */
  Craft.BunnyMate.registerStreamUploader = function (fsTypes) {
    for (var i = 0; i < fsTypes.length; i++) {
      Craft.registerUploaderClass(fsTypes[i], Craft.BunnyMate.StreamUploader);
    }
  };
})();
