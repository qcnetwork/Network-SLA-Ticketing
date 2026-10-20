<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads a map pin out of what CS pastes into the address field: a Google Maps or WhatsApp
 * share-location link, a maps.app.goo.gl short link, or plain "lat, lng" coordinates.
 */
class GeoLocation
{
    /**
     * Short-link hosts whose redirect is followed to reach the full Maps URL. Nothing else is fetched.
     */
    private const SHORT_LINK_HOSTS = ['maps.app.goo.gl', 'goo.gl', 'g.co'];

    private const MAX_REDIRECTS = 5;

    private const NUMBER = '(-?\d{1,3}(?:\.\d+)?)';

    /**
     * @return array{float, float}|null [latitude, longitude]
     */
    public static function fromText(?string $text): ?array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }

        if ($coordinates = self::parse($text)) {
            return $coordinates;
        }

        preg_match('~https?://\S+~i', $text, $match);

        return $match ? self::resolve($match[0]) : null;
    }

    /**
     * Google Maps URL for a pin, for linking out of the app.
     */
    public static function mapsUrl(float $latitude, float $longitude): string
    {
        return 'https://www.google.com/maps?q='.self::format($latitude).','.self::format($longitude);
    }

    public static function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 7, '.', ''), '0'), '.');
    }

    /**
     * @return array{float, float}|null
     */
    private static function parse(string $text): ?array
    {
        // Share links URL-encode the comma (%2C), and consent pages wrap the real URL in a parameter.
        $text = rawurldecode($text);
        $n = self::NUMBER;
        $patterns = [
            "~!3d{$n}!4d{$n}~",                                                         // the pin itself in a place URL
            "~[?&](?:q|query|ll|destination|daddr|center)=(?:loc:)?\s*{$n}\s*,\s*\+?{$n}~", // ?q=lat,lng
            "~@{$n},{$n}~",                                                             // map view centre
            '~(?<![\d.])(-?\d{1,2}\.\d{3,})\s*,\s*\+?(-?\d{1,3}\.\d{3,})(?![\d.])~',     // plain "lat, lng"
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m) && abs((float) $m[1]) <= 90 && abs((float) $m[2]) <= 180
                && ((float) $m[1] !== 0.0 || (float) $m[2] !== 0.0)) {
                return [(float) $m[1], (float) $m[2]];
            }
        }

        return null;
    }

    /**
     * Follow a short link's redirects until a URL carries coordinates.
     *
     * @return array{float, float}|null
     */
    private static function resolve(string $url): ?array
    {
        for ($hop = 0; $hop < self::MAX_REDIRECTS; $hop++) {
            if (! in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), self::SHORT_LINK_HOSTS, true)) {
                return null;
            }

            try {
                $response = Http::timeout(5)->withOptions(['allow_redirects' => false])->get($url);
            } catch (Throwable) {
                return null;
            }

            $location = $response->header('Location');
            if ($location === '') {
                // Some short links answer with a page that embeds the Maps URL instead of redirecting.
                return self::parse($response->body());
            }
            if ($coordinates = self::parse($location)) {
                return $coordinates;
            }
            $url = $location;
        }

        return null;
    }
}
