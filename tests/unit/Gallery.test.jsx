import React from 'react';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Gallery from '@components/Gallery';

const jsonResponse = (body) => ({
  ok: true,
  status: 200,
  json: async () => body,
});

const tilesPayload = (tiles, overrides = {}) => ({
  columns: 12,
  rows: 12,
  tiles,
  total: tiles.length,
  offset: 0,
  limit: 18,
  hasMore: false,
  ...overrides,
});

const photo = (index) => ({
  title: `Photo ${index}`,
  albums: [`Album ${index}`],
  thumbUrl: `https://images.example/thumb-${index}.jpg`,
  mediumUrl: `https://images.example/medium-${index}.jpg`,
  fullUrl: `https://images.example/full-${index}.jpg`,
  caption: `Caption ${index}`,
  taken: `2016-0${(index % 9) + 1}-01`,
  imageHash: `hash-${index}`,
  mediumHash: `hash-${index}`,
});

/**
 * Mocks the two endpoints Gallery calls. Tiles pages are served in call order.
 */
function mockApi({ count = 24, pages = [] } = {}) {
  let page = 0;
  const fetchMock = vi.fn(async (url) => {
    if (url.startsWith('/api/photo-count')) {
      return jsonResponse({ count });
    }
    if (url.startsWith('/api/tiles')) {
      const body = pages[page] ?? tilesPayload([]);
      page += 1;
      return jsonResponse(body);
    }
    throw new Error(`Unexpected fetch: ${url}`);
  });
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

describe('Gallery', () => {
  it('renders the tiles returned by the API and requests the first page', async () => {
    const fetchMock = mockApi({
      count: 42,
      pages: [tilesPayload([photo(1), photo(2), photo(3)], { total: 3, hasMore: false })],
    });

    render(<Gallery />);

    expect(await screen.findByAltText('Photo 1')).toHaveAttribute(
      'src',
      'https://images.example/thumb-1.jpg'
    );
    expect(screen.getByAltText('Photo 2')).toHaveAttribute(
      'src',
      'https://images.example/thumb-2.jpg'
    );
    expect(screen.getByAltText('Photo 3')).toHaveAttribute(
      'src',
      'https://images.example/thumb-3.jpg'
    );

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/photo-count?category=Travelling',
      expect.objectContaining({ signal: expect.any(AbortSignal) })
    );
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/tiles?category=Travelling&limit=18&offset=0',
      expect.objectContaining({ signal: expect.any(AbortSignal) })
    );
  });

  it('falls back from thumbUrl to thumb, then to the full-size image', async () => {
    mockApi({
      pages: [
        tilesPayload([
          {
            title: 'Thumb fallback',
            thumb: 'https://images.example/legacy-thumb.jpg',
            full: 'https://images.example/legacy-full.jpg',
          },
          {
            title: 'Full fallback',
            full: 'https://images.example/only-full.jpg',
          },
        ]),
      ],
    });

    render(<Gallery />);

    expect(await screen.findByAltText('Thumb fallback')).toHaveAttribute(
      'src',
      'https://images.example/legacy-thumb.jpg'
    );
    expect(screen.getByAltText('Full fallback')).toHaveAttribute(
      'src',
      'https://images.example/only-full.jpg'
    );
  });

  it('shows the skeleton grid while the first page is in flight', () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => new Promise(() => {}))
    );

    const { container } = render(<Gallery />);

    expect(container.querySelectorAll('.skeleton-tile')).toHaveLength(144);
    expect(container.querySelectorAll('img')).toHaveLength(0);
  });

  it('loads the next page when the user scrolls near the bottom', async () => {
    const firstPage = Array.from({ length: 18 }, (_, index) => photo(index + 1));
    const secondPage = Array.from({ length: 18 }, (_, index) => photo(index + 19));
    const fetchMock = mockApi({
      pages: [
        tilesPayload(firstPage, { total: 36, hasMore: true }),
        tilesPayload(secondPage, { total: 36, offset: 18, hasMore: false }),
      ],
    });

    render(<Gallery />);
    expect(await screen.findByAltText('Photo 1')).toBeInTheDocument();

    fireEvent.scroll(window);

    expect(await screen.findByAltText('Photo 36')).toBeInTheDocument();
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/tiles?category=Travelling&limit=18&offset=18'
    );
    expect(document.querySelectorAll('#mosaic img')).toHaveLength(36);
  });

  it('does not paginate when the server reports there is no more data', async () => {
    const fetchMock = mockApi({
      pages: [tilesPayload([photo(1), photo(2)])],
    });

    render(<Gallery />);
    expect(await screen.findByAltText('Photo 1')).toBeInTheDocument();

    fireEvent.scroll(window);

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledTimes(2);
    });
  });

  it('writes the photo count from the API into the hero stat', async () => {
    document.body.insertAdjacentHTML(
      'beforeend',
      '<div id="photo-count-stat">144</div>'
    );
    mockApi({ count: 1234, pages: [tilesPayload([photo(1)])] });

    render(<Gallery />);

    await waitFor(() => {
      expect(document.getElementById('photo-count-stat')).toHaveTextContent('1,234');
    });
    document.getElementById('photo-count-stat')?.remove();
  });

  it('opens the lightbox from a tile and supports keyboard navigation', async () => {
    mockApi({
      pages: [
        tilesPayload([photo(1), photo(2)], { total: 2, hasMore: false }),
      ],
    });

    render(<Gallery />);

    const preload = await screen.findByAltText('Photo 1');
    fireEvent.load(preload);

    const tileLink = screen.getByAltText('Photo 1').closest('a');
    fireEvent.click(tileLink);

    expect(screen.getByRole('button', { name: 'Close' })).toBeInTheDocument();
    const lightbox = document.querySelector('.km-lightbox-content');
    expect(within(lightbox).getByAltText('Photo 1')).toHaveAttribute(
      'src',
      'https://images.example/medium-1.jpg'
    );

    fireEvent.keyDown(document, { key: 'ArrowRight' });
    expect(within(lightbox).getByAltText('Photo 2')).toBeInTheDocument();

    fireEvent.keyDown(document, { key: 'Escape' });
    expect(screen.queryByRole('button', { name: 'Close' })).not.toBeInTheDocument();
  });

  it('keeps the skeleton grid for an empty tile list', async () => {
    // The Go API normally fills an empty category with 36 "Empty slot" tiles.
    // If it ever returns [], Gallery has no separate empty screen and keeps the
    // skeleton grid; this test pins that current behaviour.
    mockApi({ pages: [tilesPayload([], { total: 0, hasMore: false })] });

    const { container } = render(<Gallery />);

    await waitFor(() => {
      expect(container.querySelector('.mosaic-loading')).not.toBeInTheDocument();
    });
    expect(container.querySelectorAll('.skeleton-tile')).toHaveLength(144);
    expect(container.querySelectorAll('img')).toHaveLength(0);
  });

  it('stops showing the loading skeleton after a failed request', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url) => {
        if (url.startsWith('/api/photo-count')) {
          return jsonResponse({ count: 0 });
        }
        throw new Error('network down');
      })
    );

    const { container } = render(<Gallery />);

    await waitFor(() => {
      expect(container.querySelector('.mosaic-loading')).not.toBeInTheDocument();
    });
    expect(container.querySelectorAll('img')).toHaveLength(0);
  });
});
