/* Shared hotspot sprite contract for the admin editor and public showroom. */
(function (global) {
    'use strict';

    const assets = Object.freeze({
        info: 'assets/img/hotspot-info-v3.png',
        nav: 'assets/img/hotspot-nav-v3.png'
    });
    const size = 350;
    const textureCache = new Map();
    const colors = Object.freeze({
        forest: '#17372E',
        gold: '#D8B277',
        ivory: '#FFF8E8'
    });

    function normalizeDegrees(value, fallback = 0) {
        const degrees = Number(value);
        return Number.isInteger(degrees) && degrees >= 0 && degrees <= 359 ? degrees : fallback;
    }

    function storedDegrees(spot) {
        return normalizeDegrees(spot?.userData?.hotspotRotationDegrees, 0);
    }

    function configure(spot, type, rotation) {
        if (!spot) return spot;
        spot.userData = spot.userData || {};
        if (spot.userData.hotspotDisposed) return spot;
        const material = spot.material;
        const degrees = normalizeDegrees(rotation, storedDegrees(spot));
        spot.userData.hotspotRotationDegrees = degrees;
        spot.visible = true;
        spot.frustumCulled = false;
        spot.renderOrder = 10;
        if (material) {
            material.visible = true;
            material.transparent = true;
            material.alphaTest = 0.08;
            material.depthWrite = false;
            // Infospots sit on/near the panorama sphere; depth testing lets
            // the sphere occlude the sprite and produces crescent fragments.
            material.depthTest = false;
            material.opacity = 1;
            material.size = size;
            material.rotation = degrees * Math.PI / 180;
            material.needsUpdate = true;
            if (material.map) material.map.needsUpdate = true;
        }
        spot.userData.hotspotType = type === 'nav' ? 'nav' : 'info';
        spot.userData.hotspotAsset = assets[type === 'nav' ? 'nav' : 'info'];
        return spot;
    }

    function createFallbackTexture(type) {
        if (global.document?.createElement && global.THREE?.CanvasTexture) {
            const canvas = global.document.createElement('canvas');
            canvas.width = 96;
            canvas.height = 96;
            const context = canvas.getContext('2d');
            if (context) {
                context.clearRect(0, 0, canvas.width, canvas.height);
                context.save();
                context.translate(48, 48);
                context.fillStyle = colors.forest;
                context.strokeStyle = colors.gold;
                context.lineWidth = 4;
                context.beginPath();
                context.arc(0, 0, 38, 0, Math.PI * 2);
                context.fill();
                context.stroke();
                context.fillStyle = colors.ivory;
                if (type === 'nav') {
                    context.beginPath();
                    context.moveTo(0, -30);
                    context.lineTo(20, -10);
                    context.lineTo(8, -10);
                    context.lineTo(8, 26);
                    context.quadraticCurveTo(8, 32, 2, 32);
                    context.lineTo(-2, 32);
                    context.quadraticCurveTo(-8, 32, -8, 26);
                    context.lineTo(-8, -10);
                    context.lineTo(-20, -10);
                    context.closePath();
                    context.fill();
                } else {
                    context.beginPath();
                    context.arc(0, -17, 5, 0, Math.PI * 2);
                    context.fill();
                    context.beginPath();
                    context.moveTo(0, -9);
                    context.quadraticCurveTo(-6, -9, -6, -3);
                    context.lineTo(-6, 13);
                    context.quadraticCurveTo(-6, 19, 0, 19);
                    context.quadraticCurveTo(6, 19, 6, 13);
                    context.lineTo(6, -3);
                    context.quadraticCurveTo(6, -9, 0, -9);
                    context.closePath();
                    context.fill();
                }
                context.restore();
                const texture = new global.THREE.CanvasTexture(canvas);
                texture.needsUpdate = true;
                texture.name = `sevilla-hotspot-${type}-fallback`;
                return texture;
            }
        }
        if (!global.THREE?.DataTexture) return null;
        const width = 32;
        const pixels = new Uint8Array(width * width * 4);
        const setPixel = (x, y, color) => {
            const index = (y * width + x) * 4;
            pixels[index] = color[0]; pixels[index + 1] = color[1]; pixels[index + 2] = color[2]; pixels[index + 3] = color[3];
        };
        for (let y = 0; y < width; y += 1) {
            for (let x = 0; x < width; x += 1) {
                const localX = x - 15.5;
                const localY = y - 15.5;
                const distance = Math.hypot(localX, localY);
                if (distance > 14.5) continue;
                const onRim = distance > 12.2;
                let isSymbol = false;
                if (!onRim && type === 'nav') {
                    const shaft = Math.abs(localX) <= 2.7 && localY >= -2.5 && localY <= 10.5;
                    const head = localY >= -10.5 && localY <= -1 && Math.abs(localX) <= (localY + 10.5) * 0.9;
                    isSymbol = shaft || head;
                } else if (!onRim) {
                    const dot = Math.hypot(localX, localY + 6.5) <= 2.2;
                    const stem = Math.abs(localX) <= 2.2 && localY >= -3 && localY <= 9;
                    isSymbol = dot || stem;
                }
                const rgb = onRim ? [216, 178, 119] : (isSymbol ? [255, 248, 232] : [23, 55, 46]);
                setPixel(x, y, [rgb[0], rgb[1], rgb[2], 255]);
            }
        }
        const texture = new global.THREE.DataTexture(pixels, width, width, global.THREE.RGBAFormat, global.THREE.UnsignedByteType);
        texture.needsUpdate = true;
        texture.name = `sevilla-hotspot-${type}-fallback`;
        return texture;
    }

    function releaseOwnedTexture(spot, nextTexture = null) {
        const ownedTexture = spot?.userData?.hotspotManagedTexture;
        if (ownedTexture && ownedTexture !== nextTexture) {
            ownedTexture.dispose?.();
            if (spot.userData) spot.userData.hotspotManagedTexture = null;
        }
    }

    function loadTexture(url) {
        if (!global.THREE?.TextureLoader) return Promise.reject(new Error('TextureLoader unavailable'));
        const cached = textureCache.get(url);
        if (cached) return cached;
        const pending = new Promise((resolve, reject) => {
            const loader = new global.THREE.TextureLoader();
            loader.load(url, texture => {
                texture.needsUpdate = true;
                textureCache.set(url, Promise.resolve(texture));
                resolve(texture);
            }, undefined, error => {
                textureCache.delete(url);
                reject(error || new Error(`Unable to load hotspot texture: ${url}`));
            });
        });
        textureCache.set(url, pending);
        return pending;
    }

    function assignTexture(spot, type, sourceTexture, degrees, fallback = false, cloneSource = true) {
        if (!spot?.material || !sourceTexture) return;
        const texture = cloneSource && typeof sourceTexture.clone === 'function' ? sourceTexture.clone() : sourceTexture;
        texture.needsUpdate = true;
        releaseOwnedTexture(spot, texture);
        spot.material.map = texture;
        spot.userData = spot.userData || {};
        spot.userData.hotspotManagedTexture = texture;
        spot.userData.hotspotAssetError = false;
        spot.userData.hotspotFallback = fallback;
        configure(spot, type, degrees);
    }

    function assignFallbackTexture(spot, type, degrees) {
        if (!spot?.material || spot.userData?.hotspotDisposed) return;
        const fallback = createFallbackTexture(type);
        if (!fallback) {
            configure(spot, type, degrees);
            return;
        }
        assignTexture(spot, type, fallback, degrees, true, false);
    }

    function applyTexture(spot, type, onError) {
        const url = assets[type === 'nav' ? 'nav' : 'info'];
        if (!spot?.material) return;
        spot.userData = spot.userData || {};
        const requestToken = (Number(spot.userData.hotspotTextureRequest) || 0) + 1;
        spot.userData.hotspotTextureRequest = requestToken;
        const isCurrentRequest = () => Boolean(spot.material
            && !spot.userData?.hotspotDisposed
            && spot.userData.hotspotTextureRequest === requestToken);
        loadTexture(url).then(texture => {
            if (!isCurrentRequest()) return;
            assignTexture(spot, type, texture, storedDegrees(spot));
        }).catch(error => {
            if (!isCurrentRequest()) return;
            spot.userData = spot.userData || {};
            spot.userData.hotspotAssetError = true;
            assignFallbackTexture(spot, type, storedDegrees(spot));
            if (typeof onError === 'function') onError(error, url, spot);
        });
    }

    function create(type, position, rotation, onError) {
        // Panolens always starts its own loader and falls back to its built-in
        // icon when the URL is omitted. Give it the active asset so its async
        // callback cannot overwrite the helper texture with a mismatched icon.
        const asset = assets[type === 'nav' ? 'nav' : 'info'];
        const spot = new global.PANOLENS.Infospot(size, asset);
        configure(spot, type, rotation);
        if (position && spot.position) spot.position.copy(position);
        // Panolens resolves its image asynchronously; give the first frame a
        // deterministic marker so a draft is never blank while the PNG loads.
        assignFallbackTexture(spot, type, storedDegrees(spot));
        applyTexture(spot, type, onError);
        return spot;
    }

    function refresh(spot, type, rotation, onError) {
        if (spot?.userData?.hotspotDisposed) return spot;
        configure(spot, type, rotation);
        applyTexture(spot, type, onError);
        return spot;
    }

    function dispose(spot) {
        if (!spot) return;
        spot.userData = spot.userData || {};
        if (spot.userData.hotspotDisposed) return;
        spot.userData.hotspotDisposed = true;
        spot.userData.hotspotTextureRequest = (Number(spot.userData.hotspotTextureRequest) || 0) + 1;
        spot.visible = false;
        if (spot.material) spot.material.visible = false;
        releaseOwnedTexture(spot);
        if (spot.material) spot.material.dispose?.();
    }

    function preload(onError) {
        Object.keys(assets).forEach(type => {
            if (!global.THREE?.TextureLoader) return;
            loadTexture(assets[type]).catch(error => {
                if (typeof onError === 'function') onError(error, assets[type], type);
            });
        });
    }

    global.SevillaHotspotMaterial = Object.freeze({ assets, size, configure, create, refresh, dispose, preload });
})(window);
