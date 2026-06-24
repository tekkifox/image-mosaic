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
    <!-- Lightbox CSS -->
    <style>
    .km-lightbox-backdrop{position:fixed;inset:0;background:rgba(0,0,0,0.85);display:none;align-items:center;justify-content:center;z-index:9999}
    .km-lightbox-backdrop.km-open{display:flex}
    .km-lightbox-content{max-width:90vw;max-height:90vh;display:flex;align-items:center;justify-content:center;position:relative}
    .km-lightbox-image-wrap{position:relative;display:inline-block;max-width:100%;max-height:100%}
    .km-lightbox-content img{max-width:100%;max-height:100%;border-radius:4px;box-shadow:0 10px 30px rgba(0,0,0,.6);display:block}
    .km-lightbox-close,.km-lightbox-nav{position:absolute;background:rgba(0,0,0,.4);color:#fff;border:0;padding:8px;border-radius:4px;cursor:pointer}
    .km-lightbox-close{top:16px;right:16px}
    .km-lightbox-nav{top:50%;transform:translateY(-50%)}
    .km-lightbox-prev{left:16px}
    .km-lightbox-next{right:16px}
    /* Caption overlays the bottom center of the image */
    .km-lightbox-caption{position:absolute;left:50%;transform:translateX(-50%);bottom:16px;color:#fff;font-size:14px;max-width:calc(100% - 48px);text-align:center;background:rgba(0,0,0,0.45);padding:8px 12px;border-radius:6px;backdrop-filter:blur(4px);box-shadow:0 6px 18px rgba(0,0,0,0.5);z-index:3;pointer-events:none}
    </style>
</head>
<body>
    <header>
        <h1>12×12 Photo Mosaic</h1>
        <p class="subtitle">A simple PHP + PhotoPrism frontend showing a 12 by 12 mosaic.</p>
    </header>

    <div id="mosaic"></div>
    <div class="footer">Powered by PhotoPrism API and PHP</div>

    <!-- React mount point for gallery (will replace mosaic content) -->
    <div id="react-mosaic-root"></div>

    <!-- React gallery (buildless) -->
    <script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="/public/gallery.js"></script>
</body>
</html>
