<?php

use dokuwiki\ChangeLog\ChangeLog;
use dokuwiki\Extension\SyntaxPlugin;
use dokuwiki\File\PageResolver;

/**
 * Changes Plugin: List the most recent changes of the wiki
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 * @author     Mykola Ostrovskyy <spambox03@mail.ru>
 */
/**
 * Class syntax_plugin_changes
 */
class syntax_plugin_changes extends SyntaxPlugin
{
    /**
     * What kind of syntax are we?
     */
    public function getType()
    {
        return 'substition';
    }

    /**
     * What type of XHTML do we create?
     */
    public function getPType()
    {
        return 'block';
    }

    /**
     * Where to sort in?
     */
    public function getSort()
    {
        return 105;
    }

    /**
     * Connect pattern to lexer
     * @param string $mode
     */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('\{\{changes>[^}]*\}\}', $mode, 'plugin_changes');
    }

    /**
     * Handler to prepare matched data for the rendering process
     *
     * @param   string       $match   The text matched by the patterns
     * @param   int          $state   The lexer state for the match
     * @param   int          $pos     The character position of the matched text
     * @param   Doku_Handler $handler The Doku_Handler object
     * @return  array Return an array with all data you want to use in render
     */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        $match = substr($match, 10, -2);

        $data = [
            'ns' => [],
            'excludedpages' => [],
            'count' => 10,
            'type' => [],
            'render' => 'list',
            'render-flags' => [],
            'maxage' => null,
            'reverse' => false,
            'user' => [],
            'excludedusers' => [],
        ];

        $match = explode('&', $match);
        foreach ($match as $m) {
            if (is_numeric($m)) {
                $data['count'] = (int) $m;
            } elseif (preg_match('/(\w+)\s*=(.+)/', $m, $temp) == 1) {
                $this->handleNamedParameter($temp[1], trim($temp[2]), $data);
            } else {
                $this->addNamespace($data, trim($m));
            }
        }

        return $data;
    }

    /**
     * Handle parameters that are specified using <name>=<value> syntax
     * @param string $name
     * @param $value
     * @param array $data
     */
    protected function handleNamedParameter($name, $value, &$data)
    {
        global $ID;

        static $types = ['edit' => 'E', 'create' => 'C', 'delete' => 'D', 'minor' => 'e'];
        static $renderers = ['list', 'pagelist'];

        switch ($name) {
            case 'count':
            case 'maxage':
                $data[$name] = (int) $value;
                break;
            case 'ns':
                foreach (preg_split('/\s*,\s*/', $value) as $value) {
                    $this->addNamespace($data, $value);
                }
                break;
            case 'type':
                foreach (preg_split('/\s*,\s*/', $value) as $value) {
                    if (array_key_exists($value, $types)) {
                        $data[$name][] = $types[$value];
                    }
                }
                break;
            case 'render':
                // parse "name(flag1, flag2)" syntax
                if (preg_match('/(\w+)(?:\((.*)\))?/', $value, $match) == 1) {
                    if (in_array($match[1], $renderers)) {
                        $data[$name] = $match[1];
                        if (count($match) > 2) {
                            $flags = trim($match[2]);
                            if ($flags != '') {
                                $data['render-flags'] = preg_split('/\s*,\s*/', $flags);
                            }
                        }
                    }
                }
                break;
            case 'user':
            case 'excludedusers':
                foreach (preg_split('/\s*,\s*/', $value) as $value) {
                    $data[$name][] = $value;
                }
                break;
            case 'excludedpages':
                foreach (preg_split('/\s*,\s*/', $value) as $page) {
                    if (!empty($page)) {
                        $data[$name][] = (new PageResolver($ID))->resolveId($page);
                    }
                }
                break;
            case 'reverse':
                $data[$name] = (bool)$value;
                break;
        }
    }

    /**
     * Clean-up the namespace name and add it (if valid) into the $data array
     * @param array $data
     * @param string $namespace
     */
    protected function addNamespace(&$data, $namespace)
    {
        if (empty($namespace)) return;
        $action = ($namespace[0] == '-') ? 'exclude' : 'include';
        $namespace = cleanID(preg_replace('/^[+-]/', '', $namespace));
        if (!empty($namespace)) {
            $data['ns'][$action][] = $namespace;
        }
    }

    /**
     * Handles the actual output creation.
     *
     * @param string $format output format being rendered
     * @param Doku_Renderer $renderer the current renderer object
     * @param array $data data created by handler()
     * @return  boolean rendered correctly?
     */
    public function render($format, Doku_Renderer $renderer, $data)
    {
        global $conf;

        if ($format === 'xhtml') {
            /* @var Doku_Renderer_xhtml $renderer */
            $changes = $this->getChanges(
                $data['count'],
                $data['ns'],
                $data['excludedpages'],
                $data['type'],
                $data['user'],
                $data['maxage'],
                $data['excludedusers'],
                $data['reverse']
            );
            if (!count($changes)) return true;

            switch ($data['render']) {
                case 'list':
                    $this->renderSimpleList($changes, $renderer, $data['render-flags']);
                    break;
                case 'pagelist':
                    $this->renderPageList($changes, $renderer, $data['render-flags']);
                    break;
            }
            return true;
        } elseif ($format === 'metadata') {
            /* @var Doku_Renderer_metadata $renderer */
            $renderer->meta['relation']['depends']['rendering'][$conf['changelog']] = true;
            $renderer->meta['relation']['depends']['rendering'][$conf['media_changelog']] = true;
            return true;
        }
        return false;
    }

    /**
     * Based on getRecents() from inc/changelog.php
     *
     * @param int   $num
     * @param array $ns
     * @param array $excludedpages
     * @param array $type
     * @param array $user
     * @param int   $maxage
     * @return array
     */
    protected function getChanges($num, $ns, $excludedpages, $type, $user, $maxage, $excludedusers, $reverse)
    {
        global $conf;
        $changes = [];
        $seen = [];
        $count = 0;
        $lines = [];

        // Get global changelog
        if (file_exists($conf['changelog']) && is_readable($conf['changelog'])) {
            $lines = @file($conf['changelog']);
        }

        // Merge media changelog
        if ($this->getConf('listmedia')) {
            if (file_exists($conf['media_changelog']) && is_readable($conf['media_changelog'])) {
                $linesMedia = @file($conf['media_changelog']);
                // Add a tag to identiy the media lines
                foreach ($linesMedia as $key => $value) {
                    $value = ChangeLog::parseLogLine($value);
                    $value['extra'] = 'media';
                    $linesMedia[$key] = implode("\t", $value) . "\n";
                }
                $lines = array_merge($lines, $linesMedia);
            }
        }

        if (is_null($maxage)) {
            $maxage = (int) $conf['recent_days'] * 60 * 60 * 24;
        }

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $change = $this->handleChangelogLine(
                $lines[$i],
                $ns,
                $excludedpages,
                $type,
                $user,
                $maxage,
                $seen,
                $excludedusers
            );
            if ($change !== false) {
                $changes[] = $change;
                // break when we have enough entries
                if (++$count >= $num) break;
            }
        }

        // Date sort merged page and media changes
        if ($this->getConf('listmedia') || $reverse) {
            $dates = [];
            foreach ($changes as $change) {
                $dates[] = $change['date'];
            }
            array_multisort($dates, ($reverse ? SORT_ASC : SORT_DESC), $changes);
        }

        return $changes;
    }

    /**
     * Based on _handleRecent() from inc/changelog.php
     *
     * @param string $line
     * @param array  $ns
     * @param array  $excludedpages
     * @param array  $type
     * @param array  $user
     * @param int    $maxage
     * @param array  $seen
     * @return array|bool
     */
    protected function handleChangelogLine($line, $ns, $excludedpages, $type, $user, $maxage, &$seen, $excludedusers)
    {
        // split the line into parts
        $change = ChangeLog::parseLogLine($line);
        if ($change === false) return false;

        // skip seen ones
        if (isset($seen[$change['id']])) return false;

        // filter type
        if (!empty($type) && !in_array($change['type'], $type)) return false;

        // filter user
        if (!empty($user) && (empty($change['user']) || !in_array($change['user'], $user))) return false;

        // remember in seen to skip additional sights
        $seen[$change['id']] = 1;

        // show only not existing pages for delete
        if ($change['extra'] != 'media' && $change['type'] != 'D' && !page_exists($change['id'])) return false;

        // filter maxage
        if ($maxage && $change['date'] < (time() - $maxage)) {
            return false;
        }

        // check if it's a hidden page
        if (isHiddenPage($change['id'])) return false;

        // filter included namespaces
        if (isset($ns['include'])) {
            if (!$this->isInNamespace($ns['include'], $change['id'])) return false;
        }

        // filter excluded namespaces
        if (isset($ns['exclude'])) {
            if ($this->isInNamespace($ns['exclude'], $change['id'])) return false;
        }
        // exclude pages
        if (!empty($excludedpages)) {
            if (in_array($change['id'], $excludedpages)) {
                return false;
            }
        }

        // exclude users
        if (!empty($excludedusers)) {
            foreach ($excludedusers as $user) {
                if ($change['user'] == $user) return false;
            }
        }

        // check ACL
        $change['perms'] = auth_quickaclcheck($change['id']);
        if ($change['perms'] < AUTH_READ) return false;

        return $change;
    }

    /**
     * Check if page belongs to one of namespaces in the list
     *
     * @param array $namespaces
     * @param string $id page id
     * @return bool
     */
    protected function isInNamespace($namespaces, $id)
    {
        foreach ($namespaces as $ns) {
            if ((strpos($id, $ns . ':') === 0)) return true;
        }
        return false;
    }

    /**
     * Render via the Pagelist plugin
     *
     * @param $changes
     * @param Doku_Renderer_xhtml $renderer
     * @param $flags
     */
    protected function renderPageList($changes, $renderer, $flags)
    {
        /** @var helper_plugin_pagelist $pagelist */
        $pagelist = @plugin_load('helper', 'pagelist');
        if ($pagelist) {
            $pagelist->setFlags($flags);
            $pagelist->startList();
            foreach ($changes as $change) {
                if ($change['extra'] == 'media') continue;
                $page['id'] = $change['id'];
                $page['date'] = $change['date'];
                $page['user'] = $this->getUserName($change);
                $page['desc'] = $change['sum'];
                $pagelist->addPage($page);
            }
            $renderer->doc .= $pagelist->finishList();
        } else {
            // Fallback to the simple list renderer
            $this->renderSimpleList($changes, $renderer);
        }
    }

    /**
     * Render the day header
     *
     * @param Doku_Renderer $renderer
     * @param int $date
     */
    protected function dayheader($renderer, $date)
    {
        if ($renderer->getFormat() == 'xhtml') {
            /* @var Doku_Renderer_xhtml $renderer  */
            $renderer->doc .= '<h3 class="changes">';
            $renderer->cdata(dformat($date, $this->getConf('dayheaderfmt')));
            $renderer->doc .= '</h3>';
        } else {
            $renderer->header(dformat($date, $this->getConf('dayheaderfmt')), 3, 0);
        }
    }

    /**
     * Render with a simple list render
     *
     * @param array $changes
     * @param Doku_Renderer_xhtml $renderer
     * @param array $flags
     */
    protected function renderSimpleList($changes, $renderer, $flags = [])
    {
        global $conf;
        $flags = $this->parseSimpleListFlags($flags);

        $dayheaders_date = '';
        if ($flags['dayheaders']) {
            $dayheaders_date = date('Ymd', $changes[0]['date']);
            $this->dayheader($renderer, $changes[0]['date']);
        }

        $renderer->listu_open();
        foreach ($changes as $change) {
            if ($flags['dayheaders']) {
                $tdate = date('Ymd', $change['date']);
                if ($tdate !== $dayheaders_date) {
                    $renderer->listu_close(); // break list to insert new header
                    $this->dayheader($renderer, $change['date']);
                    $renderer->listu_open();
                    $dayheaders_date = $tdate;
                }
            }

            $renderer->listitem_open(1);
            $renderer->listcontent_open();
            if (trim($change['extra']) == 'media') {
                $renderer->internalmedia(':' . $change['id'], null, null, null, null, null, 'linkonly');
            } else {
                $renderer->internallink(':' . $change['id'], null, null, false, 'navigation');
            }
            if ($flags['summary']) {
                $renderer->cdata(' ' . $change['sum']);
            }
            if ($flags['signature']) {
                $user = $this->getUserName($change);
                $renderer->cdata(' ');
                $renderer->entity('---');
                $renderer->cdata(' ');
                $renderer->emphasis_open();
                $renderer->cdata($user . ' ');
                $renderer->emphasis_close();
            }
            if ($flags['date']) {
                //$date = strftime($conf['dformat'], $change['date']);
                $date = strftime($this->getConf('datefmt'), $change['date']);
                $renderer->cdata(' ' . $date);
            }
            if ($flags['time']) {
                $time = strftime($this->getConf('timefmt'), $change['date']);
                $renderer->cdata(' ' . $time);
            }
            $renderer->listcontent_close();
            $renderer->listitem_close();
        }
        $renderer->listu_close();
    }

    /**
     * Parse flags for the simple list render
     *
     * @param array $flags
     * @return array
     */
    protected function parseSimpleListFlags($flags)
    {
        $outFlags = ['summary' => true, 'signature' => false, 'dayheaders' => false, 'date' => false, 'time' => false];
        if (!empty($flags)) {
            foreach ($flags as $flag) {
                if (array_key_exists($flag, $outFlags)) {
                    $outFlags[$flag] = true;
                } elseif (substr($flag, 0, 2) == 'no') {
                    $flag = substr($flag, 2);
                    if (array_key_exists($flag, $outFlags)) {
                        $outFlags[$flag] = false;
                    }
                }
            }
        }
        return $outFlags;
    }

    /**
     * Get username or fallback to ip
     *
     * @param array $change
     * @return mixed
     */
    protected function getUserName($change)
    {
        /* @var DokuWiki_Auth_Plugin $auth */
        global $auth;
        if (!empty($change['user'])) {
            $user = $auth->getUserData($change['user']);
            if (empty($user)) {
                return $change['user'];
            } else {
                return $user['name'];
            }
        } else {
            return $change['ip'];
        }
    }
}
