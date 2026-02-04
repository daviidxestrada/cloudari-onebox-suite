<?php

/**
 * Minimal readme parser shim.
 *
 * The update checker only needs this class to exist. Returning an empty array
 * prevents fatal errors while keeping updates functional.
 */
class PucReadmeParser {
    /**
     * Parse readme contents. Returns an empty array to skip metadata extraction.
     *
     * @param string $contents
     * @return array
     */
    public function parse_readme_contents($contents) {
        return array();
    }
}
