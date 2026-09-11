/* global Craft, Garnish, $ */

(function () {
  'use strict';

  if (typeof Craft.BunnyMate === 'undefined') {
    Craft.BunnyMate = {};
  }

  /**
   * The Bunny Stream panel on an asset's edit screen.
   *
   * The panel lives inside Craft's element form, so its controls carry ids but no name
   * attributes, and it posts to its own endpoint rather than relying on the host form.
   */
  Craft.BunnyMate.VideoPanel = Garnish.Base.extend({
    $panel: null,
    $button: null,
    $spinner: null,
    assetId: null,

    init: function () {
      this.$panel = $('#bunnymate-video-panel');
      if (!this.$panel.length) {
        return;
      }

      this.assetId = this.$panel.data('asset-id');
      this.$button = $('#bunnymate-refresh-video');
      this.$spinner = $('#bunnymate-refresh-spinner');

      // `activate` covers click and keyboard
      this.addListener(this.$button, 'activate', 'refresh');

      // Enter inside the panel must not submit the asset form
      this.addListener(this.$panel, 'keydown', function (ev) {
        if (ev.keyCode === Garnish.RETURN_KEY) {
          ev.preventDefault();
          ev.stopPropagation();
          this.refresh();
        }
      });
    },

    refresh: function () {
      var self = this;

      this.$button.addClass('disabled').attr('disabled', 'disabled');
      this.$spinner.removeClass('hidden');

      Craft.sendActionRequest('POST', '_bunnymate/videos/refresh', {
        data: {assetId: this.assetId},
      })
        .then(function (response) {
          if (response.data && response.data.html) {
            self.replacePanel(response.data.html);
          }
          Craft.cp.displayNotice(
            (response.data && response.data.message) ||
              Craft.t('_bunnymate', 'Video refreshed.')
          );
        })
        .catch(function (error) {
          Craft.cp.displayError(
            (error.response && error.response.data && error.response.data.message) ||
              Craft.t('_bunnymate', 'Couldn’t refresh the video.')
          );
          self.$button.removeClass('disabled').removeAttr('disabled');
          self.$spinner.addClass('hidden');
        });
    },

    /**
     * @param {String} html
     */
    replacePanel: function (html) {
      var $new = $(html);
      this.$panel.replaceWith($new);
      // Old listeners pointed at DOM that's gone
      this.destroy();
      new Craft.BunnyMate.VideoPanel();
    },
  });
})();
