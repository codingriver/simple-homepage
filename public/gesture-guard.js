(function () {
  'use strict';

  if (!('ontouchstart' in window) || window.innerWidth > 768) {
    return;
  }

  var startX = 0;
  var startY = 0;
  var edgeSize = 24;

  window.addEventListener('touchstart', function (event) {
    if (!event.touches || event.touches.length !== 1) {
      return;
    }
    startX = event.touches[0].clientX;
    startY = event.touches[0].clientY;
  }, { passive: true });

  window.addEventListener('touchmove', function (event) {
    if (!event.touches || event.touches.length !== 1) {
      return;
    }

    var touch = event.touches[0];
    var dx = touch.clientX - startX;
    var dy = touch.clientY - startY;
    var fromLeftEdge = startX <= edgeSize && dx > 18;
    var fromRightEdge = startX >= window.innerWidth - edgeSize && dx < -18;

    if ((fromLeftEdge || fromRightEdge) && Math.abs(dx) > Math.abs(dy) * 1.5) {
      event.preventDefault();
    }
  }, { passive: false });
})();
