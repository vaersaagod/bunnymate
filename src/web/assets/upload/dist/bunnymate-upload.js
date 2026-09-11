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
  Craft.BunnyMate.Uploader = Garnish.Base.extend(
    {
      $container: null,
      $dropzone: null,
      $fileInput: null,
      $browseBtn: null,
      $list: null,
      uploads: null,

      init: function (container, settings) {
        this.$container = $(container);
        this.setSettings(settings, Craft.BunnyMate.Uploader.defaults);
        this.uploads = [];

        this.$dropzone = this.$container.find('[data-bunnymate-dropzone]');
        this.$fileInput = this.$container.find('[data-bunnymate-file-input]');
        this.$browseBtn = this.$container.find('[data-bunnymate-browse]');
        this.$list = this.$container.find('[data-bunnymate-list]');

        // `activate` rather than `click`, so keyboard users get there too
        this.addListener(this.$browseBtn, 'activate', function (ev) {
          ev.preventDefault();
          this.$fileInput.trigger('click');
        });

        this.addListener(this.$fileInput, 'change', function () {
          var files = this.$fileInput[0].files;
          this.handleFiles(files);
          // Reset, so picking the same file twice still fires a change
          this.$fileInput.val('');
        });

        this.addListener(this.$dropzone, 'dragover dragenter', function (ev) {
          ev.preventDefault();
          ev.stopPropagation();
          this.$dropzone.addClass('hover');
        });

        this.addListener(this.$dropzone, 'dragleave dragend drop', function (ev) {
          ev.preventDefault();
          ev.stopPropagation();
          this.$dropzone.removeClass('hover');
        });

        this.addListener(this.$dropzone, 'drop', function (ev) {
          var dt = ev.originalEvent.dataTransfer;
          if (dt && dt.files && dt.files.length) {
            this.handleFiles(dt.files);
          }
        });
      },

      /**
       * @param {FileList} files
       */
      handleFiles: function (files) {
        for (var i = 0; i < files.length; i++) {
          this.uploadFile(files[i]);
        }
      },

      /**
       * Asks Craft for credentials, then hands the file to Bunny.
       *
       * @param {File} file
       */
      uploadFile: function (file) {
        var self = this;
        var row = this.addRow(file.name);

        Craft.sendActionRequest('POST', '_bunnymate/upload/prepare', {
          data: {
            folderId: this.settings.folderId,
            filename: file.name,
          },
        })
          .then(function (response) {
            return Craft.BunnyMate.loadTus().then(function () {
              self.startTusUpload(file, response.data, row);
            });
          })
          .catch(function (error) {
            var message =
              (error.response && error.response.data && error.response.data.message) ||
              Craft.t('_bunnymate', 'Couldn’t start the upload.');
            self.failRow(row, message);
          });
      },

      /**
       * @param {File} file
       * @param {Object} credentials
       * @param {Object} row
       */
      startTusUpload: function (file, credentials, row) {
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
            self.setRowProgress(row, bytesTotal ? bytesUploaded / bytesTotal : 0);
          },
          onSuccess: function () {
            self.completeUpload(credentials.assetId, row);
          },
          onError: function (error) {
            self.abortUpload(credentials.assetId);
            self.failRow(row, error.message || Craft.t('_bunnymate', 'Upload failed.'));
          },
        });

        this.uploads.push(upload);

        // Resume a previous attempt at this file, if there is one
        upload.findPreviousUploads().then(function (previous) {
          if (previous.length) {
            upload.resumeFromPreviousUpload(previous[0]);
          }
          upload.start();
        });
      },

      /**
       * @param {Number} assetId
       * @param {Object} row
       */
      completeUpload: function (assetId, row) {
        var self = this;
        Craft.sendActionRequest('POST', '_bunnymate/upload/complete', {
          data: {assetId: assetId},
        })
          .then(function () {
            self.finishRow(row);
            self.trigger('uploadComplete', {assetId: assetId});
          })
          .catch(function () {
            // The file is on Bunny either way; the webhook will catch the status up
            self.finishRow(row);
          });
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
          // Nothing useful to do here; the asset is cleaned up on the next prune
        });
      },

      /**
       * @param {String} filename
       * @returns {Object}
       */
      addRow: function (filename) {
        var $row = $('<div class="bunnymate-upload-row"/>').appendTo(this.$list);
        $('<div class="bunnymate-upload-name"/>').text(filename).appendTo($row);
        var $bar = $('<div class="bunnymate-upload-bar"/>').appendTo($row);
        var $fill = $('<div class="bunnymate-upload-fill"/>').appendTo($bar);
        var $status = $('<div class="bunnymate-upload-status"/>')
          .text(Craft.t('_bunnymate', 'Preparing…'))
          .appendTo($row);
        return {$row: $row, $fill: $fill, $status: $status};
      },

      setRowProgress: function (row, ratio) {
        var pct = Math.round(ratio * 100);
        row.$fill.css('width', pct + '%');
        row.$status.text(pct + '%');
      },

      finishRow: function (row) {
        row.$fill.css('width', '100%');
        row.$row.addClass('is-done');
        row.$status.text(Craft.t('_bunnymate', 'Uploaded, now encoding'));
      },

      failRow: function (row, message) {
        row.$row.addClass('is-error');
        row.$status.text(message);
      },

      destroy: function () {
        for (var i = 0; i < this.uploads.length; i++) {
          try {
            this.uploads[i].abort();
          } catch (e) {
            // Already finished or never started
          }
        }
        this.uploads = [];
        this.base();
      },
    },
    {
      defaults: {
        folderId: null,
      },
    }
  );
})();
