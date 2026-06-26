import React, { useEffect, useState } from 'react';

const CountryPlaces = () => {
  const [countries, setCountries] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const controller = new AbortController();

    fetch('api.php?action=country-places&category=Travelling', { signal: controller.signal })
      .then((r) => r.json())
      .then((data) => {
        if (Array.isArray(data?.countries)) {
          setCountries(data.countries);
        }
      })
      .catch((err) => {
        if (err.name === 'AbortError') return;
      })
      .finally(() => {
        setLoading(false);
      });

    return () => controller.abort();
  }, []);

  return (
    <div>
      <div className="section-heading">
        <h2>Just some of the places we discovered along the way...</h2>
      </div>

      <div className="country-grid">
        {loading && countries.length === 0
          ? Array.from({ length: 5 }).map((_, i) => (
              <article key={i} className="country-card country-card-loading">
                <div className="country-card-header">
                  <div className="skeleton-line skeleton-line-title" />
                  <div className="skeleton-line skeleton-line-subtitle" />
                </div>
                <div className="place-list place-list-loading">
                  {Array.from({ length: 6 }).map((__, idx) => (
                    <div key={idx} className="skeleton-line skeleton-line-item" />
                  ))}
                </div>
              </article>
            ))
          : countries.map((country) => (
              <article key={country.country} className="country-card">
                <div className="country-card-header">
                  <h3>{country.country}</h3>
                  <p>{country.photoCount} photos analyzed</p>
                </div>
                <ul className="place-list">
                  {country.places.map((place) => (
                    <li key={place.name}>{place.name}</li>
                  ))}
                </ul>
              </article>
            ))}
      </div>

      {!loading && countries.length === 0 && (
        <p className="country-empty-state">No location metadata was found in the current PhotoPrism dataset.</p>
      )}
    </div>
  );
};

export default CountryPlaces;
