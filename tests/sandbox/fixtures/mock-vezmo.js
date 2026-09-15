/* TEST-ONLY stand-in for VezmoPay's vezmo.js: mounts the mock secure page and
   relays its postMessage events to .on() handlers, like the real SDK. */
(function () {
  window.Vezmo = function () {
    var handlers = {};
    var frame = null;
    var self = this;
    function emit(name, data) {
      (handlers[name] || []).forEach(function (cb) { try { cb(data); } catch (e) {} });
    }
    this.mount = function (el, o) {
      // The real SDK derives the frame URL from the client token; this fixture's
      // token carries it base64url-encoded (see the mock API).
      var url = (window.vezmopay_params && window.vezmopay_params.iframeUrl) || '';
      var tok = (o && o.clientToken) || '';
      if (!url && tok.indexOf('tok_') === 0) {
        var b64 = tok.slice(4).replace(/-/g, '+').replace(/_/g, '/');
        while (b64.length % 4) { b64 += '='; }
        try { url = atob(b64); } catch (e) { url = ''; }
      }
      frame = document.createElement('iframe');
      frame.src = url;
      frame.width = '100%';
      frame.height = '620';
      frame.setAttribute('allow', 'payment *; storage-access *');
      el.appendChild(frame);
      window.addEventListener('message', function (e) {
        if (!frame || e.source !== frame.contentWindow) { return; }
        var t = e.data && e.data.type;
        if (!t || t.indexOf('vezmo:secure-payment:') !== 0) { return; }
        var name = t.slice('vezmo:secure-payment:'.length);
        // The real vezmo.js relays exactly these (SUFFIX_BY_NAME) and drops
        // everything else — `requires_action` included. Match it, or this
        // fixture hides bugs that only exist against the real SDK.
        var RELAYED = ['ready','processing','success','error','pending','already-paid','expired','cancel'];
        if (name === 'resize') {
          var h = Number(e.data.height);
          if (h > 200 && h < 4000) { frame.style.height = h + 'px'; }
          return;
        }
        if (RELAYED.indexOf(name) === -1) { return; }
        emit(name, e.data);
      });
      return self;
    };
    this.pay = function () {
      if (frame && frame.contentWindow) {
        frame.contentWindow.postMessage({ type: 'vezmo:secure-payment:submit' }, '*');
      }
    };
    this.on = function (name, cb) {
      (handlers[name] = handlers[name] || []).push(cb);
      return self;
    };
  };
})();
