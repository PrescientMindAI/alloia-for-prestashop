<?php
/**
 * AI commerce attribution helpers (UTM + referrer, no new AlloIA cookies).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AlloiaAttribution
{
    public const UTM_SOURCE = 'alloia_ai';
    public const ORDER_MARKER = 'AlloIA AI attribution (utm_source=alloia_ai)';

  /** @var string[] */
    private static $knownAiReferrers = [
        'chat.openai.com', 'chatgpt.com', 'perplexity.ai', 'claude.ai',
        'copilot.microsoft.com', 'gemini.google.com', 'you.com', 'phind.com', 'poe.com', 'bing.com',
    ];

    /**
     * True when current request has utm_source=alloia_ai or AI referrer host.
     */
    public static function isAiAttributedRequest(): bool
    {
        if (Tools::getValue('utm_source') === self::UTM_SOURCE) {
            return true;
        }

        $referer = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
        if ($referer === '') {
            return false;
        }

        $host = parse_url($referer, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower($host);
        foreach (self::$knownAiReferrers as $domain) {
            if ($host === $domain || substr($host, -(strlen($domain) + 1)) === '.' . $domain) {
                return true;
            }
        }

        return false;
    }

    /**
     * Append utm_source=alloia_ai to a URL when not already present.
     */
    public static function appendUtm(string $url): string
    {
        if ($url === '') {
            return $url;
        }
        if (stripos($url, 'utm_source=') !== false) {
            return $url;
        }
        $sep = (strpos($url, '?') !== false) ? '&' : '?';

        return $url . $sep . 'utm_source=' . self::UTM_SOURCE;
    }
}
