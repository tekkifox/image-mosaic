import React from 'react';
import ReactDOM from 'react-dom/client';
import Gallery from '@components/Gallery';
import './styles/index.css';

// Register Service Worker for offline caching and performance
// Temporarily disabled for debugging
// if ('serviceWorker' in navigator) {
//   window.addEventListener('load', () => {
//     navigator.serviceWorker.register('/dist/service-worker.js', { scope: '/' })
//       .then((registration) => {
//         console.log('Service Worker registered:', registration);
//       })
//       .catch((error) => {
//         console.warn('Service Worker registration failed:', error);
//       });
//   });
// }

const root = ReactDOM.createRoot(document.getElementById('react-mosaic-root'));
root.render(<Gallery />);
