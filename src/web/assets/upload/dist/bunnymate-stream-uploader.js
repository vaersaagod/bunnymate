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
    _streamQueue: null,
    _streamActive: 0,
    _streamProgress: null,
    _streamProgressKey: 0,

    init: function ($element, settings) {
      this._folderInfo = {};
      this._folderInfoPromises = {};
      this._streamUploads = [];
      this._streamQueue = [];
      this._streamProgress = {};
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
      this.enqueueStreamUpload(file);

      // Craft's own onFileAdd counts every file in the drop, so it knows when the last one is
      // in and can report the rejected ones and reset. It never sees this one, so count it here.
      if (++this._totalFileCounter === data.originalFiles.length) {
        this._totalFileCounter = 0;
        this._validFileCounter = 0;
        this.processErrorMessages();
      }

      // Not false: blueimp adds the files of a drop in a $.each over this handler's return
      // value, so false ends the loop and every file after this one is silently dropped.
      // Nothing is submitted over XHR either way, since autoUpload is off and only
      // data.submit() sends a file.
      return true;
    },

    /**
     * Returns how many videos may upload at once, from the `maxConcurrentUploads` setting.
     *
     * @returns {Number}
     */
    maxConcurrentUploads: function () {
      return Math.max(1, parseInt(Craft.BunnyMate.maxConcurrentUploads, 10) || 1);
    },

    /**
     * Queues a video for upload, and starts it if there's a free slot.
     *
     * A queued file counts as in progress straight away, so Craft sees one batch from the
     * first file to the last rather than a series of batches, and the progress bar covers
     * the whole of it.
     *
     * @param {File} file
     */
    enqueueStreamUpload: function (file) {
      var progressKey = ++this._streamProgressKey;

      if (this._inProgressCounter === 0) {
        // Craft's progress bar is shared with every other uploader on the page, so it only
        // wears Bunny's colours while one of ours is actually running
        Garnish.$bod.addClass('bunnymate-uploading');
        this.$element.trigger('fileuploadstart');
      }

      this._inProgressCounter++;
      this._streamProgress[progressKey] = {loaded: 0, total: file.size};
      this._streamQueue.push({file: file, progressKey: progressKey});
      this.startQueuedUploads();
    },

    /**
     * Starts queued uploads until the concurrency cap is reached.
     */
    startQueuedUploads: function () {
      while (this._streamActive < this.maxConcurrentUploads() && this._streamQueue.length) {
        var next = this._streamQueue.shift();
        this._streamActive++;
        this.uploadToStream(next.file, next.progressKey);
      }
    },

    /**
     * Prepares and uploads a single video.
     *
     * Preparing happens here rather than when the file is queued, so the upload signature,
     * which expires, is only issued once the upload is about to use it.
     *
     * @param {File} file
     * @param {Number} progressKey
     */
    uploadToStream: function (file, progressKey) {
      var self = this;

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
          self.startTusUpload(file, credentials, progressKey);
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
              Craft.t('bunnymate', 'Couldn’t start the upload.'),
            progressKey
          );
        });
    },

    /**
     * @param {File} file
     * @param {Object} credentials
     * @param {Number} progressKey
     */
    startTusUpload: function (file, credentials, progressKey) {
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
          self._streamProgress[progressKey] = {loaded: bytesUploaded, total: bytesTotal};
          self.reportProgress();
        },
        onSuccess: function () {
          self.finishUpload(credentials, progressKey);
        },
        onError: function (error) {
          self.abortUpload(credentials.assetId);
          self.failUpload(file, error.message || Craft.t('bunnymate', 'Upload failed.'), progressKey);
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
     * Reports the progress of every upload in the air as one, the way Craft's own
     * fileuploadprogressall does. Per upload, several running at once would have the shared
     * progress bar jumping back and forth between them.
     */
    reportProgress: function () {
      var loaded = 0;
      var total = 0;
      for (var key in this._streamProgress) {
        if (Object.prototype.hasOwnProperty.call(this._streamProgress, key)) {
          loaded += this._streamProgress[key].loaded;
          total += this._streamProgress[key].total;
        }
      }
      if (!total) {
        return;
      }
      this.keepFieldProgressVisible();
      // Craft's index reads loaded/total off the progress event
      this.$element.trigger('fileuploadprogressall', [
        {
          loaded: loaded,
          total: total,
        },
      ]);
    },

    /**
     * Shows an Assets field's progress bar again if Craft hid it while a batch is still going.
     *
     * An Assets field hides its bar on any failure, and after each success once it has
     * rendered the new element and isLastUpload() says so. That render is a request of its
     * own, and by the time it's back the count has moved on, so the second-to-last video of a
     * batch is taken for the last. Craft's field has the same gap with its own uploads. Rather
     * than guess at every way the bar gets hidden, it's put back while uploads remain. The
     * asset index never hides its bar early, so this only applies to fields.
     */
    keepFieldProgressVisible: function () {
      if (this._inProgressCounter === 0) {
        return;
      }
      // Craft keeps a reference to the field on its container, which is our element
      var input = this.$element.data('elementSelect');
      if (!input || !input.progressBar || !(input instanceof Craft.AssetSelectInput)) {
        return;
      }
      if (!input.progressBar.$progressBar.hasClass('hidden')) {
        return;
      }
      this.$element.addClass('uploading');
      input.progressBar.showProgressBar();
    },

    /**
     * @param {Object} credentials
     * @param {Number} progressKey
     */
    finishUpload: function (credentials, progressKey) {
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
          self.endUpload(progressKey);
        });
    },

    /**
     * @param {File} file
     * @param {String} message
     * @param {Number} progressKey
     */
    failUpload: function (file, message, progressKey) {
      // Shaped like a failed blueimp upload, so Craft's own fileuploadfail handler shows the
      // message and is the only one to. Showing it here as well gave every failure two
      // notices: this one, and Craft's generic "Upload failed" for want of a message.
      // The asset index and Assets fields read jqXHR off the data argument; the image
      // uploader calls response() and reads jqXHR off that.
      var jqXHR = {
        responseJSON: {
          message: message,
          filename: file.name,
        },
      };
      this.$element.trigger('fileuploadfail', [
        {
          files: [file],
          jqXHR: jqXHR,
          response: function () {
            return {jqXHR: jqXHR};
          },
        },
      ]);
      this.endUpload(progressKey);
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

    /**
     * @param {Number} progressKey
     */
    endUpload: function (progressKey) {
      // A finished upload's bytes stay in the sum until the batch is over. Dropping them
      // while others are still running would send the progress bar backwards.
      var progress = this._streamProgress[progressKey];
      if (progress && progress.loaded < progress.total) {
        // Except a failed upload's unsent bytes, which would hold the bar short of 100%
        progress.total = progress.loaded;
      }

      this._streamActive = Math.max(0, this._streamActive - 1);

      // Before the count goes down, not after. Craft's handler asks isLastUpload(), which
      // expects the upload that just ended to still be counted, so decrementing first had
      // the second-to-last upload of a batch hide the progress bar and refresh the index.
      this.$element.trigger('fileuploadalways');

      this._inProgressCounter = Math.max(0, this._inProgressCounter - 1);
      if (this._inProgressCounter === 0) {
        Garnish.$bod.removeClass('bunnymate-uploading');
        this._streamProgress = {};
        return;
      }

      this.startQueuedUploads();
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
      this._streamQueue = [];
      this._streamActive = 0;
      this._streamProgress = {};
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
