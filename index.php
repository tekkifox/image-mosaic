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
            padding: 0;
        }
        .tile {
            position: relative;
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
        /* More Info Button */
        .tile-info-button {
            position: absolute;
            top: 8px;
            right: 8px;
            background-color: rgba(0, 0, 0, 0.6);
            color: white;
            border: none;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            font-size: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0; /* Hidden by default */
            transition: opacity 0.2s ease;
            z-index: 10 !important; /* Above the image and caption */
        }
        .tile:hover .tile-info-button {
            opacity: 1; /* Show on hover */
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

        /* Lightbox CSS */
        .km-lightbox-backdrop{position:fixed;inset:0;background:rgba(0,0,0,0.85);display:none;align-items:center;justify-content:center;z-index:9999}
        .km-lightbox-backdrop.km-open{display:flex}
        .km-lightbox-content{
            max-width:90vw;
            max-height:90vh;
            display:flex;
            align-items:center;
            justify-content:center;
            position:relative;
        }
        .km-lightbox-image-wrap{
            position: relative;
            display: inline-block;
            max-width: 100%;
            max-height: 100%;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 1; /* Base z-index for the image wrapper */
        }
        .km-lightbox-content img{
            max-width:100%;
            max-height:100%;
            border-radius:4px;
            box-shadow:0 10px 30px rgba(0,0,0,.6);
            display:block;
            object-fit: contain;
            position: relative; /* Establishing stacking context */
            z-index: 1; /* Ensure image is below absolutely positioned buttons */
        }
        .km-lightbox-close,.km-lightbox-nav{
            position:absolute;
            background:rgba(0,0,0,.4);
            color:#fff;
            border:0;
            padding:8px;
            border-radius:4px;
            cursor:pointer
        }
        .km-lightbox-close{top:16px;right:16px}
        .km-lightbox-nav{top:50%;transform:translateY(-50%)}
        .km-lightbox-prev{left:16px; z-index: 1000 !important;}
        .km-lightbox-next{right:16px; z-index: 1000 !important;}
        /* Caption overlays the bottom center of the image */
        .km-lightbox-caption{
            position:absolute;
            max-height: 50px;
            top: 90%;
            left:50%;
            transform:translateX(-50%);
            bottom:16px;
            color:#fff;
            font-size:14px;
            max-width:calc(100% - 48px);
            text-align:center;
            background:rgba(0,0,0,0.45);
            padding:8px 12px;
            border-radius:6px;
            backdrop-filter:blur(4px);
            box-shadow:0 6px 18px rgba(0,0,0,0.5);
            z-index:3;
            pointer-events:none;
            white-space:pre-wrap;
        }

        /* Info Button */
        .km-lightbox-info-button {
            position: absolute;
            bottom: 10px;
            right: 10px;
            background-color: rgba(0, 0, 0, 0.6);
            color: white;
            border: none;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            font-size: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 1;
            transition: opacity 0.2s ease;
            z-index: 10 !important; /* Above caption and image */
        }
        .km-lightbox-image-wrap:hover .km-lightbox-info-button {
            opacity: 1; /* Show on hover of the image wrapper */
        }

        /* Info Lightbox Specific Styles */
        .km-info-lightbox .km-lightbox-close {
            position: absolute;
            top: 10px;
            right: 10px;
            background: none;
            border: none;
            color: #fff;
            font-size: 24px;
            cursor: pointer;
        }

        .km-info-lightbox-content {
            background: #222;
            color: #fff;
            padding: 20px;
            border-radius: 8px;
            max-width: 400px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            box-shadow: 0 10px 30px rgba(0,0,0,.6);
        }

        .km-info-lightbox-content h3 {
            margin-top: 0;
            color: #eee;
            text-align: center;
            font-size: 1.5em;
            border-bottom: 1px solid #444;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }

        .km-info-lightbox-content h4 {
            margin-bottom: 5px;
            color: #ccc;
        }

        .km-info-lightbox-content ul {
            list-style: none;
            padding: 0;
            margin: 0 0 15px 0;
        }

        .km-info-lightbox-content li {
            background-color: #333;
            margin-bottom: 5px;
            padding: 8px 10px;
            border-radius: 4px;
        }

        .km-info-lightbox-content p {
            color: #bbb;
            font-size: 0.9em;
        }
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