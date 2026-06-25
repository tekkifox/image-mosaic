import React from 'react';

const Tile = ({ item, isLoading, onTileClick, onInfoClick }) => {
  if (isLoading) {
    return (
      <div className="tile tile-scattered skeleton-tile">
        <a href="#" onClick={(e) => e.preventDefault()}>
          <div className="skeleton-img" />
        </a>
      </div>
    );
  }

  // Generate random rotation and offset for scattered effect
  const tileRotation = (Math.random() - 0.5) * 6;
  const tileOffsetX = (Math.random() - 0.5) * 20;
  const tileOffsetY = (Math.random() - 0.5) * 20;
  const shadowRotation = tileRotation * 0.3;
  const shadowOffsetX = Math.sin((shadowRotation * Math.PI) / 180) * 15;
  const shadowOffsetY = Math.cos((shadowRotation * Math.PI) / 180) * 15;

  return (
    <div
      className="tile tile-scattered"
      style={{
        transform: `rotate(${tileRotation}deg) translateX(${tileOffsetX}px) translateY(${tileOffsetY}px)`,
        boxShadow: `${shadowOffsetX}px ${shadowOffsetY + 12}px 28px rgba(0,0,0,0.45), ${shadowOffsetX * 0.5}px ${shadowOffsetY * 0.5 + 4}px 12px rgba(0,0,0,0.25)`,
      }}
    >
      <a href="#" onClick={(e) => { e.preventDefault(); onTileClick(); }}>
        <img src={item.src} data-src={item.src} alt={item.alt} loading="lazy" />
      </a>
      <button
        className="tile-info-button"
        onClick={(e) => {
          e.stopPropagation();
          e.preventDefault();
          onInfoClick();
        }}
      >
        i
      </button>
    </div>
  );
};

export default Tile;
