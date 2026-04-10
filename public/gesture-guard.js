(function () {
  if (window.__navGestureGuardInstalled) {
    return;
  }
  window.__navGestureGuardInstalled = true;

  var startX = 0;
  var startY = 0;
  var trackingEdgeSwipe = false;
  var EDGE_SIZE = 32;
  var HORIZONTAL_TRIGGER = 12;

  function hasHorizontalScrollableAncestor(node) {
    var el = node instanceof Element ? node : null;
    while (el && el !== document.body && el !== document.documentElement) {
      var style = window.getComputedStyle(el);
      var overflowX = style.overflowX || '';
      var scrollable = (overflowX === 'auto' || overflowX === 'scroll') && el.scrollWidth > el.clientWidth + 2;
      if (scrollable) {
        return true;
      }
      el = el.parentElement;
    }
    return false;
  }

  function shouldBlockHorizontalGesture(target, deltaX, deltaY) {
    if (Math.abs(deltaX) <= Math.abs(deltaY)) {
      return false;
    }
    if (Math.abs(deltaX) < HORIZONTAL_TRIGGER) {
      return false;
    }
    return !hasHorizontalScrollableAncestor(target);
  }

  document.addEventListener('touchstart', function (event) {
    if (!event.touches || event.touches.length !== 1) {
      trackingEdgeSwipe = false;
      return;
    }
    var touch = event.touches[0];
    startX = touch.clientX;
    startY = touch.clientY;
    trackingEdgeSwipe = startX <= EDGE_SIZE || startX >= (window.innerWidth - EDGE_SIZE);
  }, { passive: true });

  document.addEventListener('touchmove', function (event) {
    if (!trackingEdgeSwipe || !event.touches || event.touches.length !== 1) {
      return;
    }
    var touch = event.touches[0];
    var deltaX = touch.clientX - startX;
    var deltaY = touch.clientY - startY;
    if (shouldBlockHorizontalGesture(event.target, deltaX, deltaY)) {
      event.preventDefault();
    }
  }, { passive: false });

  document.addEventListener('touchend', function () {
    trackingEdgeSwipe = false;
  }, { passive: true });

  window.addEventListener('wheel', function (event) {
    if (shouldBlockHorizontalGesture(event.target, event.deltaX, event.deltaY)) {
      event.preventDefault();
    }
  }, { passive: false });
})();
