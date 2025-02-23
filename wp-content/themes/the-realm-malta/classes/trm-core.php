<?php

class TRM_Core
{
    /**
     * Logs information for debugging
     */
    public function write_log($data): void
    {
        if (true === WP_DEBUG) {
            if (is_array($data) || is_object($data)) {
                error_log(print_r($data, true));
            } else {
                error_log($data);
            }
        }
    }
}
