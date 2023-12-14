<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

/**
 * Changes Plugin: List the most recent changes of the wiki
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 * @author     Mykola Ostrovskyy <spambox03@mail.ru>
 */

/**
 * Class action_plugin_changes
 */
class action_plugin_changes extends ActionPlugin
{
    /**
     * Register callbacks
     * @param EventHandler $controller
     */
    public function register(EventHandler $controller)
    {
        $controller->register_hook('PARSER_CACHE_USE', 'BEFORE', $this, 'beforeParserCacheUse');
    }

    /**
     * Handle PARSER_CACHE_USE:BEFORE event
     * @param Event $event
     */
    public function beforeParserCacheUse($event)
    {
        global $ID;
        $cache = $event->data;
        if (isset($cache->mode) && ($cache->mode == 'xhtml')) {
            $depends = p_get_metadata($ID, 'relation depends');
            if (!empty($depends) && isset($depends['rendering'])) {
                $this->addDependencies($cache, array_keys($depends['rendering']));
            }
        }
    }

    /**
     * Add extra dependencies to the cache
     */
    protected function addDependencies($cache, $depends)
    {
        // Prevent "Warning: in_array() expects parameter 2 to be array, null given"
        if (!is_array($cache->depends)) {
            $cache->depends = [];
        }
        if (!array_key_exists('files', $cache->depends)) {
            $cache->depends['files'] = [];
        }

        foreach ($depends as $file) {
            if (!in_array($file, $cache->depends['files']) && @file_exists($file)) {
                $cache->depends['files'][] = $file;
            }
        }
    }
}
