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
      if (params && params.folderId) {
        this.loadFolderInfo(params.folderId);
      }
    },

    /**
     * @param {Number|String} folderId
     * @returns {Promise}
     */
    loadFolderInfo: function (folderId) {
      var self = this;

      if (typeof this._folderInfoPromises[folderId] !== 'undefined') {
        return this._folderInfoPromises[folderId];
      }

      this._folderInfoPromises[folderId] = Craft.sendActionRequest(
        'GET',
        '_bunnymate/upload/folder-info',
        {params: {folderId: folderId}}
      )
        .then(function (response) {
          self._folderInfo[folderId] = response.data;
          return response.data;
        })
        .catch(function () {
          // Treat an unknown folder as a regular one, so uploads still work
          self._folderInfo[folderId] = {stream: false};
          return self._folderInfo[folderId];
        });

      return this._folderInfoPromises[folderId];
    },

    /**
     * @param {File} file
     * @returns {Boolean}
     */
    shouldStream: function (file) {
      var folderId = this.formData ? this.formData.folderId : null;
      if (!folderId) {
        return false;
      }
      var info = this._folderInfo[folderId];
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
      this.$element.trigger('fileuploadstart');

      Craft.sendActionRequest('POST', '_bunnymate/upload/prepare', {
        data: {
          folderId: this.formData.folderId,
          filename: file.name,
        },
      })
        .then(function (response) {
          return Craft.BunnyMate.loadTus().then(function () {
            self.startTusUpload(file, response.data);
          });
        })
        .catch(function (error) {
          self.failUpload(
            file,
            (error.response && error.response.data && error.response.data.message) ||
              Craft.t('_bunnymate', 'Couldn’t start the upload.')
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
        onProgress: function (bytesUploaded, bytesTotal) {
          // Craft's index reads loaded/total off the progress event
          self.$element.trigger('fileuploadprogressall', {
            loaded: bytesUploaded,
            total: bytesTotal,
          });
        },
        onSuccess: function () {
          self.finishUpload(credentials);
        },
        onError: function (error) {
          self.abortUpload(credentials.assetId);
          self.failUpload(file, error.message || Craft.t('_bunnymate', 'Upload failed.'));
        },
      });

      this._streamUploads.push(upload);

      upload.findPreviousUploads().then(function (previous) {
        if (previous.length) {
          upload.resumeFromPreviousUpload(previous[0]);
        }
        upload.start();
      });
    },

    /**
     * @param {Object} credentials
     */
    finishUpload: function (credentials) {
      var self = this;

      Craft.sendActionRequest('POST', '_bunnymate/upload/complete', {
        data: {assetId: credentials.assetId},
      })
        .catch(function () {
          // The bytes are on Bunny either way; the webhook will catch the status up
        })
        .then(function () {
          // Craft's index reads the result off a CustomEvent's detail
          self.$element[0].dispatchEvent(
            new CustomEvent('fileuploaddone', {
              bubbles: true,
              detail: {
                assetId: credentials.assetId,
                filename: credentials.filename,
              },
            })
          );
          self.endUpload();
        });
    },

    /**
     * @param {File} file
     * @param {String} message
     */
    failUpload: function (file, message) {
      Craft.cp.displayError(message);
      this.$element.trigger('fileuploadfail');
      this.endUpload();
    },

    /**
     * @param {Number} assetId
     */
    abortUpload: function (assetId) {
      if (!assetId) {
        return;
      }
      Craft.sendActionRequest('POST', '_bunnymate/upload/abort', {
        data: {assetId: assetId},
      }).catch(function () {
        // Nothing useful to do here
      });
    },

    endUpload: function () {
      this._inProgressCounter = Math.max(0, this._inProgressCounter - 1);
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
