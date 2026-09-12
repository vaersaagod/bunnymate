(function () {
  'use strict';

  var HLS_TYPE = 'application/x-mpegURL';

  /**
   * Returns whether hls.js can run here.
   *
   * `canPlayType` can't answer this: Chrome and Edge report "maybe" for HLS but can't actually
   * decode it, so trusting them leaves Chromium with neither native playback nor hls.js. What
   * hls.js needs is Media Source Extensions, so that's what's checked -- the same test
   * `Hls.isSupported()` makes, done before spending a request on the library.
   *
   * Browsers without MSE are iOS WebKit, where HLS plays natively and hls.js would be useless
   * anyway. Everywhere else, hls.js is preferred even where native HLS exists: it's the only
   * path that behaves the same across browsers, and it's what the resolution bounds hang off.
   */
  function canUseHlsJs() {
    var MediaSource = window.MediaSource || window.WebKitMediaSource;
    return !!(MediaSource && typeof MediaSource.isTypeSupported === 'function');
  }

  function sourcesOf(video) {
    return Array.prototype.slice.call(video.querySelectorAll('source'));
  }

  /**
   * Moves data-src onto src, which is what actually starts any downloading.
   */
  function activate(video) {
    sourcesOf(video).forEach(function (source) {
      var src = source.getAttribute('data-src');
      if (src) {
        source.setAttribute('src', src);
        source.removeAttribute('data-src');
      }
    });
    video.load();
  }

  function hlsSource(video) {
    var match = sourcesOf(video).filter(function (source) {
      return source.getAttribute('type') === HLS_TYPE;
    })[0];
    return match ? match.getAttribute('src') || match.getAttribute('data-src') : null;
  }

  var hlsPromise = null;

  function loadHlsJs(url) {
    if (window.Hls) {
      return Promise.resolve();
    }
    if (hlsPromise) {
      return hlsPromise;
    }
    hlsPromise = new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      script.src = url;
      script.async = true;
      script.onload = resolve;
      script.onerror = function () {
        hlsPromise = null;
        reject(new Error('hls.js could not be loaded'));
      };
      document.head.appendChild(script);
    });
    return hlsPromise;
  }

  function heightAttr(video, name) {
    var value = parseInt(video.getAttribute(name), 10);
    return isNaN(value) || value <= 0 ? null : value;
  }

  /**
   * Holds adaptive playback between the given heights.
   *
   * hls.js has no resolution bounds of its own: the ceiling is an index into its level list,
   * and the floor is a bitrate, so both have to be worked out from the levels the manifest
   * actually turned out to have.
   */
  function applyBounds(hls, Hls, minHeight, maxHeight) {
    hls.on(Hls.Events.MANIFEST_PARSED, function () {
      var levels = hls.levels || [];
      if (!levels.length) {
        return;
      }

      if (maxHeight) {
        var ceiling = -1;
        levels.forEach(function (level, index) {
          if (level.height && level.height <= maxHeight) {
            ceiling = index;
          }
        });
        // Leave it alone rather than capping to nothing if every level is taller
        if (ceiling >= 0) {
          hls.autoLevelCapping = ceiling;
        }
      }

      if (minHeight) {
        var floor = null;
        levels.forEach(function (level, index) {
          if (floor === null && level.height && level.height >= minHeight) {
            floor = index;
          }
        });
        if (floor !== null) {
          // Starting there as well, so the floor applies before the first measurement rather
          // than after it has already picked something lower
          hls.startLevel = floor;
          if (levels[floor].bitrate) {
            hls.config.minAutoBitrate = levels[floor].bitrate;
          }
        }
      }
    });
  }

  /**
   * Attaches hls.js to a video, so browsers without native HLS get adaptive playback rather
   * than the capped MP4 rendition the markup falls back to.
   */
  function attachHls(video, url) {
    var src = hlsSource(video);
    if (!src) {
      return;
    }
    loadHlsJs(url)
      .then(function () {
        if (!window.Hls || !window.Hls.isSupported()) {
          return;
        }
        var Hls = window.Hls;
        var hls = new Hls({ capLevelToPlayerSize: true });
        applyBounds(
          hls,
          Hls,
          heightAttr(video, 'data-bunnymate-min-height'),
          heightAttr(video, 'data-bunnymate-max-height')
        );
        hls.loadSource(src);
        hls.attachMedia(video);
        video.bunnymateHls = hls;
      })
      .catch(function () {
        // The MP4 source in the markup already covers this; nothing more to do
      });
  }

  function setUp(video) {
    if (video.bunnymateReady) {
      return;
    }
    video.bunnymateReady = true;

    // Only a lazyloaded tag has sources to move. Calling load() on one that already has its
    // src would reset the element, interrupting playback that may have started without us.
    if (video.getAttribute('data-bunnymate-lazyload') !== null) {
      activate(video);
    }

    // Where hls.js can't run, the markup already covers it: iOS WebKit plays the HLS source
    // natively, and anything else falls through to the MP4
    var hlsJsUrl = video.getAttribute('data-bunnymate-hls');
    if (hlsJsUrl && canUseHlsJs()) {
      attachHls(video, hlsJsUrl);
    }
  }

  /**
   * Everything this script does is worth deferring until the video is near the viewport,
   * lazyloaded or not: hls.js starts buffering the moment it attaches, so attaching it to an
   * off-screen video just moves the download earlier. A video that's already in view
   * intersects on the observer's first check, so nothing above the fold waits for this.
   */
  function init() {
    var videos = document.querySelectorAll('video[data-bunnymate-video]');

    Array.prototype.forEach.call(videos, function (video) {
      if (typeof IntersectionObserver === 'undefined') {
        setUp(video);
        return;
      }

      var observer = new IntersectionObserver(
        function (entries) {
          entries.forEach(function (entry) {
            if (!entry.isIntersecting) {
              return;
            }
            observer.unobserve(entry.target);
            setUp(entry.target);
          });
        },
        { rootMargin: '200px' }
      );

      observer.observe(video);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
