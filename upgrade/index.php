<?php
/**
 * @author    AlloIA Team
 * @copyright 2026 AlloIA
 * @license   MIT
 */

// Past date = do not cache this 403 response
header('Expires: Thu, 01 Jan 2025 00:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('HTTP/1.0 403 Forbidden');
