/*!
 * CandyPush — Candy 推送服务浏览器客户端
 *
 * 协议（与 Candy\Extend\Tools\Push\Events 对应）：
 *   发 → {"act":"login","app":"home","uid":34,"tags":["dept-12"]}
 *   发 → {"act":"join","tag":"dept-12"} / {"act":"leave","tag":"dept-12"}
 *   发 → {"act":"ping"}
 *   收 → {"act":"welcome","client_id":"..."} / {"act":"login","ok":true,"uid":"home:34","flushed":2}
 *   收 → {"act":"message","msg_id":"...","title":"","body":"","payload":{}}
 *
 * 用法：
 *   var push = new CandyPush({ app: 'home', uid: 34, tags: ['dept-12'], debug: true });
 *   push.on('message', function (m) { ... });
 *   push.on('login',   function (r) { ... });
 *   push.connect();
 *
 * 无依赖、UMD；不自动猜测 uid —— uid 必须由服务端登录态下发。
 *
 * @author 刘森 (fingerboy@qq.com)
 * @date   2026-11-24
 */
;(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.CandyPush = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var DEFAULTS = {
        url: '',            // 空则自动推导 ws(s)://<host>:4020
        app: 'home',        // 应用名（与 Registry 注册的一致）
        uid: null,          // 必填：业务用户 id
        tags: [],           // 初始分组（如 dept-12）
        heartbeat: 30000,   // 心跳间隔 ms，0 关闭
        reconnect: true,    // 断线自动重连
        maxRetry: 10,       // 最大重连次数
        retryBase: 1000,    // 退避基数 ms（指数增长，上限 30s）
        debug: false
    };

    function log(self, args) {
        if (!self.opts.debug) return;
        try {
            var a = ['[CandyPush]'].concat([].slice.call(args));
            console.log.apply(console, a);
        } catch (e) { /* 忽略 */ }
    }

    function CandyPush(options) {
        if (!(this instanceof CandyPush)) return new CandyPush(options);
        var o = {}, k;
        for (k in DEFAULTS) { if (DEFAULTS.hasOwnProperty(k)) o[k] = DEFAULTS[k]; }
        if (options) { for (k in options) { if (options.hasOwnProperty(k)) o[k] = options[k]; } }

        this.opts = o;
        this.ws = null;
        this.retry = 0;
        this.handlers = {};
        this.hbTimer = null;
        this.rcTimer = null;
        this.manualClose = false;
        this.state = 'idle';   // idle | connecting | open | closed
    }

    /** 取连接地址 */
    CandyPush.prototype.url = function () {
        if (this.opts.url) return this.opts.url;
        var https = (typeof location !== 'undefined' && location.protocol === 'https:');
        var host = (typeof location !== 'undefined' && location.hostname) ? location.hostname : '127.0.0.1';
        return (https ? 'wss://' : 'ws://') + host + ':4020';
    };

    /** 注册事件：open / welcome / login / message / error / close / reconnect */
    CandyPush.prototype.on = function (evt, fn) {
        if (typeof fn !== 'function') return this;
        (this.handlers[evt] || (this.handlers[evt] = [])).push(fn);
        return this;
    };

    /** 移除事件 */
    CandyPush.prototype.off = function (evt, fn) {
        var list = this.handlers[evt];
        if (!list) return this;
        if (!fn) { delete this.handlers[evt]; return this; }
        for (var i = list.length - 1; i >= 0; i--) {
            if (list[i] === fn) list.splice(i, 1);
        }
        return this;
    };

    /** 触发事件 */
    CandyPush.prototype.emit = function (evt, payload) {
        var list = this.handlers[evt];
        if (list) {
            for (var i = 0; i < list.length; i++) {
                try { list[i](payload); } catch (e) { log(this, ['handler error', evt, e]); }
            }
        }
        var all = this.handlers['*'];
        if (all) {
            for (var j = 0; j < all.length; j++) {
                try { all[j]({ type: evt, data: payload }); } catch (e2) { /* 忽略 */ }
            }
        }
    };

    /** 建立连接（幂等） */
    CandyPush.prototype.connect = function () {
        if (this.state === 'connecting' || this.state === 'open') return this;
        if (typeof WebSocket === 'undefined') {
            this.emit('error', { message: '浏览器不支持 WebSocket' });
            return this;
        }
        this.manualClose = false;
        this.state = 'connecting';

        var self = this;
        var ws;

        try {
            ws = new WebSocket(this.url());
        } catch (e) {
            this.state = 'closed';
            this.emit('error', { message: 'WebSocket 创建失败：' + e.message });
            return this;
        }

        this.ws = ws;

        ws.onopen = function () {
            self.state = 'open';
            self.retry = 0;
            log(self, ['connected', self.url()]);
            self.emit('open', { url: self.url() });
            self.login();
            self.startHeartbeat();
        };

        ws.onmessage = function (ev) {
            var m;
            try { m = JSON.parse(ev.data); } catch (e) {
                log(self, ['invalid frame', ev.data]);
                return;
            }
            if (!m || typeof m !== 'object') return;

            var act = String(m.act || '');
            if (act === 'ping') {
                self.send({ act: 'pong' });
                return;
            }
            if (act === 'pong') {
                self.emit('pong', m);
                return;
            }
            if (act === 'welcome') {
                self.emit('welcome', m);
                return;
            }
            if (act === 'login') {
                if (m.ok) {
                    log(self, ['login ok', m.uid, 'flushed=' + (m.flushed || 0)]);
                } else {
                    self.emit('error', { message: m.message || '登录失败', frame: m });
                }
                self.emit('login', m);
                return;
            }
            if (act === 'message') {
                self.emit('message', m);
                return;
            }
            self.emit('frame', m);
        };

        ws.onerror = function () {
            self.emit('error', { message: 'WebSocket 错误' });
        };

        ws.onclose = function (ev) {
            self.state = 'closed';
            self.stopHeartbeat();
            log(self, ['closed', ev && ev.code]);
            self.emit('close', { code: ev && ev.code, reason: ev && ev.reason });
            if (!self.manualClose && self.opts.reconnect) {
                self.scheduleReconnect();
            }
        };

        return this;
    };

    /** 发送 login（连接建立后自动调用） */
    CandyPush.prototype.login = function () {
        if (this.opts.uid === null || this.opts.uid === undefined || this.opts.uid === '') {
            this.emit('error', { message: 'uid 为空，无法 login（应由服务端登录态下发）' });
            return this;
        }
        var frame = { act: 'login', app: this.opts.app, uid: this.opts.uid };
        if (this.opts.tags && this.opts.tags.length) frame.tags = this.opts.tags;
        this.send(frame);
        return this;
    };

    /** 加入分组 */
    CandyPush.prototype.join = function (tag) {
        this.send({ act: 'join', tag: tag });
        return this;
    };

    /** 退出分组 */
    CandyPush.prototype.leave = function (tag) {
        this.send({ act: 'leave', tag: tag });
        return this;
    };

    /** 发送任意帧 */
    CandyPush.prototype.send = function (obj) {
        if (!this.ws || this.state !== 'open') {
            log(this, ['send skipped (not open)', obj]);
            return false;
        }
        try {
            this.ws.send(JSON.stringify(obj));
            return true;
        } catch (e) {
            log(this, ['send failed', e]);
            return false;
        }
    };

    /** 主动关闭（不再重连） */
    CandyPush.prototype.close = function () {
        this.manualClose = true;
        this.stopHeartbeat();
        if (this.rcTimer) { clearTimeout(this.rcTimer); this.rcTimer = null; }
        if (this.ws) {
            try { this.ws.close(); } catch (e) { /* 忽略 */ }
        }
        this.state = 'idle';
        return this;
    };

    /** 心跳 */
    CandyPush.prototype.startHeartbeat = function () {
        var self = this;
        this.stopHeartbeat();
        if (!this.opts.heartbeat || this.opts.heartbeat <= 0) return;
        this.hbTimer = setInterval(function () {
            self.send({ act: 'ping' });
        }, this.opts.heartbeat);
    };

    CandyPush.prototype.stopHeartbeat = function () {
        if (this.hbTimer) {
            clearInterval(this.hbTimer);
            this.hbTimer = null;
        }
    };

    /** 指数退避重连 */
    CandyPush.prototype.scheduleReconnect = function () {
        var self = this;
        if (this.retry >= this.opts.maxRetry) {
            this.emit('error', { message: '重连次数已达上限（' + this.opts.maxRetry + '）' });
            return;
        }
        this.retry++;
        var delay = Math.min(this.opts.retryBase * Math.pow(2, this.retry - 1), 30000);
        log(this, ['reconnect in', delay + 'ms', 'attempt', this.retry]);
        this.emit('reconnect', { attempt: this.retry, delay: delay });
        if (this.rcTimer) clearTimeout(this.rcTimer);
        this.rcTimer = setTimeout(function () {
            self.rcTimer = null;
            self.connect();
        }, delay);
    };

    CandyPush.version = '1.0.0';

    return CandyPush;
}));
