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
            display: none;
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
            gap: 0;
            /*max-width: 1280px;*/
            /*margin: 24px auto;*/
            padding: 0;
        }
        /* --- CSS Stylesheet --- */
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
            display: none;
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
            gap: 0;
            /* max-width: 1280px; */
            /* margin: 24px auto; */
            padding: 0;
        }
        .tile {
            position: relative; /* Crucial for overlay positioning */
            aspect-ratio: 1 / 1;
            overflow: hidden;
            border-radius: 0;
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
        /* --- New Styles for Title Overlay --- */
        /* --- CSS Stylesheet --- */
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
            display: none;
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
            gap: 0;
            /* max-width: 1280px; */
            /* margin: 24px auto; */
            padding: 0;
        }
        .tile {
            position: relative; /* Crucial for overlay positioning */
            aspect-ratio: 1 / 1;
            overflow: hidden;
            border-radius: 0;
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

        /* --- Updated Styles for Title Overlay --- */
        .album-overlay {
            position: absolute;
            bottom: 0; /* Position at the bottom */
            left: 0;
            width: 100%;
            padding: 8px;
            background-color: rgba(0, 0, 0, 0.5); /* Increased transparency (darker) */
            color: white;
            font-size: 0.8rem; /* Smaller text */
            text-align: center;
            opacity: 1; /* Always visible */
            transition: opacity 0.3s ease;
        }

        /* --- End Updated Styles for Title Overlay --- */
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

        /* Show overlay when tile is hovered */
        .tile:hover .album-overlay {
            opacity: 1; /* Reveal when hovered */
        }

        /* Link/Container styles are handled by the 'a' tag wrapping everything */
    /* --- End New Styles for Title Overlay --- */
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
            display: none;
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

                    // --- START: Title/Overlay Implementation ---
                    const link = document.createElement('a');
                    link.href = tile.link || '#';
                    link.target = '_blank';
                    link.rel = 'noreferrer noopener';

                    // Image element (forms the background)
                    const image = document.createElement('img');
                    image.src = tile.thumb;
                    image.alt = tile.title || 'Photo mosaic tile';
                    image.loading = 'lazy';
                    link.appendChild(image);

                    // Album Title Overlay (visible on hover)
                    if (tile.albums && Array.isArray(tile.albums) && tile.albums.length > 0) {
                        const titleDiv = document.createElement('div');
                        titleDiv.className = 'album-overlay';
                        // Display all titles, joined by ', '.
                        titleDiv.textContent = tile.albums.join(', '); 
                        link.appendChild(titleDiv);
                    }
                    // --- END: Title/Overlay Implementation ---

                    item.appendChild(link);
                    mosaic.appendChild(item);
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
