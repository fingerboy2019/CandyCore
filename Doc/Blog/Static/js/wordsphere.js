/**
 * 侧栏 3D 球体文字云（CSS3DRenderer）
 *
 * 实现参考 CSDN《3D词云图》：CSS3DObject 球面布局、TWEEN 入场、TrackballControls 拖拽
 *
 * @see https://blog.csdn.net/qiao_yue/article/details/149032935
 */
(function (global) {
    'use strict';

    var TONE_COLORS = {
        blue: '#2563eb',
        indigo: '#4f46e5',
        cyan: '#0891b2',
        violet: '#7c3aed',
        green: '#059669',
        orange: '#ea580c',
        pink: '#db2777',
        teal: '#0d9488',
        amber: '#d97706'
    };

    var WEIGHT_SIZE = { 1: 12, 2: 14, 3: 17, 4: 21, 5: 26 };

    /**
     * 球面均匀布局目标位（与参考文 createSphereLayout 一致）
     *
     * @param {number} count 词数量
     * @param {number} radius 球半径
     * @return {THREE.Object3D[]}
     */
    function createSphereLayout(THREE, count, radius) {
        var vector = new THREE.Vector3();
        var targets = [];

        for (var i = 0; i < count; i++) {
            var phi = Math.acos(-1 + (2 * i) / count);
            var theta = Math.sqrt(count * Math.PI) * phi;
            var object = new THREE.Object3D();

            object.position.x = radius * Math.cos(theta) * Math.sin(phi);
            object.position.y = radius * Math.sin(theta) * Math.sin(phi);
            object.position.z = radius * Math.cos(phi);

            vector.copy(object.position).multiplyScalar(2);
            object.lookAt(vector);

            targets.push(object);
        }

        return targets;
    }

    /**
     * TWEEN 过渡到球面布局
     *
     * @param {THREE.CSS3DObject[]} objects 词对象列表
     * @param {THREE.Object3D[]} targets 目标位姿
     * @param {number} duration 动画毫秒
     * @param {Function} onRender 每帧重绘
     */
    function transformToSphere(objects, targets, duration, onRender) {
        var TWEEN = global.TWEEN;
        if (!TWEEN) {
            objects.forEach(function (obj, i) {
                var t = targets[i];
                obj.position.copy(t.position);
                obj.rotation.copy(t.rotation);
            });
            onRender();
            return;
        }

        TWEEN.removeAll();
        objects.forEach(function (object, i) {
            var target = targets[i];
            new TWEEN.Tween(object.position)
                .to({ x: target.position.x, y: target.position.y, z: target.position.z }, duration)
                .easing(TWEEN.Easing.Quadratic.InOut)
                .start();
            new TWEEN.Tween(object.rotation)
                .to({ x: target.rotation.x, y: target.rotation.y, z: target.rotation.z }, duration)
                .easing(TWEEN.Easing.Quadratic.InOut)
                .start();
        });

        new TWEEN.Tween({})
            .to({}, duration * 1.1)
            .onUpdate(onRender)
            .start();
    }

    /**
     * 初始化侧栏 3D 词云球
     *
     * @param {HTMLElement} root 含 stage 容器与 JSON 数据
     */
    function init(root) {
        if (!global.THREE || !global.THREE.CSS3DObject || root.dataset.bound === '1') {
            return;
        }
        root.dataset.bound = '1';

        var stage = root.querySelector('.blog-wordsphere-stage');
        var dataEl = root.querySelector('.wordsphere-data');
        if (!stage || !dataEl) {
            return;
        }

        var words;
        try {
            words = JSON.parse(dataEl.textContent || '[]');
        } catch (e) {
            return;
        }
        if (!words.length) {
            return;
        }

        var THREE = global.THREE;
        var wrap = stage.parentElement;
        var width = Math.max(wrap.clientWidth, 160);
        var height = Math.max(wrap.clientHeight, 200);
        var radius = 95;
        var vector = new THREE.Vector3();

        var camera = new THREE.PerspectiveCamera(42, width / height, 1, 5000);
        camera.position.z = 320;

        var scene = new THREE.Scene();
        var objects = [];

        words.forEach(function (word) {
            var weight = parseInt(word.weight, 10) || 1;
            var baseSize = WEIGHT_SIZE[weight] || WEIGHT_SIZE[3];
            var color = TONE_COLORS[word.tone] || TONE_COLORS.blue;

            var element = document.createElement('div');
            element.className = 'blog-wordsphere-word';
            element.textContent = word.name;
            element.style.color = color;
            element.style.fontSize = baseSize + 'px';
            element.style.textShadow = '0 0 8px ' + color;
            element.dataset.href = word.href || '#';
            element.title = word.name;

            element.addEventListener('click', function (e) {
                var href = element.dataset.href;
                if (href && href !== '#') {
                    e.preventDefault();
                    global.location.href = href;
                }
            });

            var object = new THREE.CSS3DObject(element);
            object.userData = { baseSize: baseSize, href: word.href || '#' };

            var scatter = radius * 3.2;
            object.position.x = (Math.random() - 0.5) * scatter;
            object.position.y = (Math.random() - 0.5) * scatter;
            object.position.z = (Math.random() - 0.5) * scatter;
            object.rotation.x = Math.random() * Math.PI * 2;
            object.rotation.y = Math.random() * Math.PI * 2;
            object.rotation.z = Math.random() * Math.PI * 2;

            vector.copy(object.position).multiplyScalar(2);
            object.lookAt(vector);

            scene.add(object);
            objects.push(object);
        });

        var targets = createSphereLayout(THREE, objects.length, radius);

        var renderer = new THREE.CSS3DRenderer();
        renderer.setSize(width, height);
        stage.appendChild(renderer.domElement);

        var controls = null;
        var userDragging = false;
        var autoSpin = !global.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var idleSpin = 0.0035;
        var rafId = 0;

        if (THREE.TrackballControls) {
            controls = new THREE.TrackballControls(camera, renderer.domElement);
            controls.rotateSpeed = 1.2;
            controls.zoomSpeed = 0.6;
            controls.panSpeed = 0.4;
            controls.noZoom = false;
            controls.noPan = true;
            controls.staticMoving = false;
            controls.dynamicDampingFactor = 0.12;
            controls.minDistance = 180;
            controls.maxDistance = 520;
            controls.addEventListener('start', function () {
                userDragging = true;
            });
            controls.addEventListener('end', function () {
                userDragging = false;
            });
            controls.addEventListener('change', render);
        }

        function render() {
            renderer.render(scene, camera);
        }

        function resize() {
            width = Math.max(wrap.clientWidth, 160);
            height = Math.max(wrap.clientHeight, 200);
            camera.aspect = width / height;
            camera.updateProjectionMatrix();
            renderer.setSize(width, height);
            render();
        }

        function animate() {
            rafId = global.requestAnimationFrame(animate);

            if (global.TWEEN) {
                global.TWEEN.update();
            }
            if (controls) {
                controls.update();
            }

            if (autoSpin && !userDragging) {
                scene.rotation.y += idleSpin;
            }

            objects.forEach(function (object) {
                var distance = camera.position.distanceTo(object.position);
                var base = object.userData.baseSize || 14;
                var fontSize = Math.max(10, base + (280 - distance) / 28);
                object.element.style.fontSize = fontSize + 'px';
            });

            render();
        }

        transformToSphere(objects, targets, 2200, render);

        if (global.ResizeObserver) {
            var ro = new global.ResizeObserver(resize);
            ro.observe(wrap);
        } else {
            global.addEventListener('resize', resize);
        }

        resize();
        animate();

        global.addEventListener('beforeunload', function () {
            global.cancelAnimationFrame(rafId);
            if (controls && controls.dispose) {
                controls.dispose();
            }
        });
    }

    global.BlogWordSphere = { init: init };
}(window));
