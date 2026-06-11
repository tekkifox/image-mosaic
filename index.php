<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>12x12 Image Mosaic</title>
    <style>
        body {
            margin: 0;
            font-family: system-ui, sans-serif;
            background: #111;
            color: #f5f5f5;
        }
        header {
            padding: 20px;
            text-align: center;
            background: #151515;
            border-bottom: 1px solid #333;
        }
        h1 {
            margin: 0 0 8px;
            font-size: 1.7rem;
        }
        .subtitle {
            margin: 0;
            color: #aaa;
        }
        #mosaic {
            display: grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap: 6px;
            max-width: 1280px;
            margin: 24px auto;
            padding: 0 12px 40px;
        }
        .tile {
            position: relative;
            aspect-ratio: 1 / 1;
            overflow: hidden;
            border-radius: 10px;
            background: #222;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .tile:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 35px rgba(0, 0, 0, 0.35);
        }
        .tile img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .tile a {
            display: block;
            width: 100%;
            height: 100%;
        }
        .loader,
        .error {
            max-width: 1280px;
            margin: 48px auto;
            padding: 24px;
            text-align: center;
            color: #eee;
        }
        .error {
            color: #f77;
        }
        .footer {
            text-align: center;
            color: #888;
            padding-bottom: 24px;
        }
    </style>
</head>
<body>
    <header>
        <h1>12×12 Photo Mosaic</h1>
        <p class="subtitle">A simple PHP + PhotoPrism frontend showing a 12 by 12 mosaic.</p>
    </header>

    <div id="status" class="loader">Loading mosaic...</div>
    <div id="mosaic"></div>
    <div class="footer">Powered by PhotoPrism API and PHP</div>

    <script>
        async function loadMosaic() {
            const status = document.getElementById('status');
            const mosaic = document.getElementById('mosaic');

            try {
                const response = await fetch('api.php?action=tiles');
                const data = await response.json();

                if (!response.ok || data.error) {
                    throw new Error(data.error || response.statusText);
                }

                mosaic.style.gridTemplateColumns = `repeat(${data.columns}, minmax(0, 1fr))`;
                mosaic.innerHTML = '';

                data.tiles.forEach(tile => {
                    const item = document.createElement('div');
                    item.className = 'tile';

                    const link = document.createElement('a');
                    link.href = tile.link || '#';
                    link.target = '_blank';
                    link.rel = 'noreferrer noopener';

                    const image = document.createElement('img');
                    image.src = tile.thumb;
                    image.alt = tile.title || 'Photo mosaic tile';
                    image.loading = 'lazy';

                    link.appendChild(image);
                    item.appendChild(link);
                    mosaic.appendChild(item);
                });

                status.style.display = 'none';
            } catch (error) {
                status.className = 'error';
                status.textContent = 'Failed to load mosaic: ' + error.message;
            }
        }

        loadMosaic();
    </script>
</body>
</html>
