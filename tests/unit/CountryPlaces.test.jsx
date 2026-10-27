import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import CountryPlaces from '@components/CountryPlaces';

const jsonResponse = (body) => ({
  ok: true,
  status: 200,
  json: async () => body,
});

describe('CountryPlaces', () => {
  it('shows placeholder cards while the aggregation request is in flight', () => {
    vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})));

    const { container } = render(<CountryPlaces />);

    expect(container.querySelectorAll('.country-card-loading')).toHaveLength(5);
  });

  it('renders countries, photo counts and place lists from the API', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        jsonResponse({
          countries: [
            {
              country: 'Japan',
              photoCount: 12,
              places: [{ name: 'Tokyo', count: 7 }, { name: 'Kyoto', count: 5 }],
            },
            {
              country: 'Australia',
              photoCount: 3,
              places: [{ name: 'Melbourne', count: 3 }],
            },
          ],
        })
      )
    );

    render(<CountryPlaces />);

    expect(await screen.findByRole('heading', { name: 'Japan' })).toBeInTheDocument();
    expect(screen.getByText('12 photos analyzed')).toBeInTheDocument();
    expect(screen.getByText('Tokyo')).toBeInTheDocument();
    expect(screen.getByText('Kyoto')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Australia' })).toBeInTheDocument();
    expect(screen.getByText('Melbourne')).toBeInTheDocument();
  });

  it('shows the empty state when the API returns no countries', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ countries: [] })));

    render(<CountryPlaces />);

    expect(
      await screen.findByText(
        'No location metadata was found in the current PhotoPrism dataset.'
      )
    ).toBeInTheDocument();
  });

  it('shows the empty state when the request fails', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        throw new Error('network down');
      })
    );

    render(<CountryPlaces />);

    await waitFor(() => {
      expect(
        screen.getByText(
          'No location metadata was found in the current PhotoPrism dataset.'
        )
      ).toBeInTheDocument();
    });
  });
});
