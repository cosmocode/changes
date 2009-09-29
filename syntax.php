<?php
/**
 * Changes Plugin: List the most recent changes of the wiki
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 * @author     Mykola Ostrovskyy <spambox03@mail.ru>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

class syntax_plugin_changes extends DokuWiki_Syntax_Plugin {

    /**
     * Return some info
     */
    function getInfo(){
        return confToHash(dirname(__FILE__).'/info.txt');
    }
    /**
     * What kind of syntax are we?
     */
    function getType(){
        return 'substition';
    }
    /**
     * Where to sort in?
     */
    function getSort(){
        return 105;
    }
    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
      $this->Lexer->addSpecialPattern('\{\{changes>[^}]*\}\}',$mode,'plugin_changes');
    }
    /**
     * Handle the match
     */
    function handle($match, $state, $pos, &$handler){
        $match = substr($match,10,-2);

        $data = array(
            'ns' => array(),
            'count' => 10,
            'type' => array(),
            'render' => 'list',
            'render-flags' => array(),
        );

        $match = explode('&',$match);
        foreach($match as $m){
            if(is_numeric($m)){
                $data['count'] = (int) $m;
            }else{
                if(preg_match('/(\w+)\s*=(.+)/', $m, $temp) == 1){
                    $this->handleNamedParameter($temp[1], trim($temp[2]), $data);
                }else{
                    $data['ns']['include'][] = cleanID($m);
                }
            }
        }

        return $data;
    }

    /**
     * Handle parameters that are specified uing <name>=<value> syntax
     */
    function handleNamedParameter($name, $value, &$data) {
        static $types = array('edit' => 'E', 'create' => 'C', 'delete' => 'D', 'minor' => 'e');
        static $renderers = array('list', 'pagelist');
        switch($name){
            case 'count': $data[$name] = intval($value); break;
            case 'ns':
                foreach(preg_split('/\s*,\s*/', $value) as $value){
                    $action = ($value{0} == '-')?'exclude':'include';
                    $data[$name][$action][] = cleanID(preg_replace('/^[+-]/', '', $value));
                }
                break;
            case 'type':
                foreach(preg_split('/\s*,\s*/', $value) as $value){
                    if(array_key_exists($value, $types)){
                        $data[$name][] = $types[$value];
                    }
                }
                break;
            case 'render':
                // parse "name(flag1, flag2)" syntax
                if(preg_match('/(\w+)(?:\((.*)\))?/', $value, $match) == 1){
                    if(in_array($match[1], $renderers)){
                        $data[$name] = $match[1];
                        $flags = trim($match[2]);
                        if($flags != ''){
                            $data['render-flags'] = preg_split('/\s*,\s*/', $flags);
                        }
                    }
                }
                break;
        }
    }

    /**
     * Create output
     */
    function render($mode, &$R, $data) {
        if($mode == 'xhtml'){
            $changes = $this->getChanges($data['count'], $data['ns'], $data['type']);
            if(!count($changes)) return true;

            switch($data['render']){
                case 'list': $this->renderSimpleList($changes, $R); break;
                case 'pagelist': $this->renderPageList($changes, $R, $data['render-flags']); break;
            }
            return true;
        }elseif($mode == 'metadata'){
            global $conf;
            $R->meta['relation']['depends']['rendering'][$conf['changelog']] = true;
            return true;
        }
        return false;
    }

    /**
     * Based on getRecents() from inc/changelog.php
     */
    function getChanges($num, $ns, $type) {
        global $conf;
        $changes = array();
        $seen = array();
        $count = 0;
        $lines = @file($conf['changelog']);

        for($i = count($lines)-1; $i >= 0; $i--){
            $change = $this->handleChangelogLine($lines[$i], $ns, $type, $seen);
            if($change !== false){
                $changes[] = $change;
                // break when we have enough entries
                if(++$count >= $num) break;
            }
        }
        return $changes;
    }

    /**
     * Based on _handleRecent() from inc/changelog.php
     */
    function handleChangelogLine($line, $ns, $type, &$seen) {
        // split the line into parts
        $change = parseChangelogLine($line);
        if($change===false) return false;

        // skip seen ones
        if(isset($seen[$change['id']])) return false;

        // filter type
        if(!empty($type) && !in_array($change['type'], $type)) return false;

        // remember in seen to skip additional sights
        $seen[$change['id']] = 1;

        // check if it's a hidden page
        if(isHiddenPage($change['id'])) return false;

        // filter included namespaces
        if(isset($ns['include'])){
            if(!$this->isInNamespace($ns['include'], $change['id'])) return false;
        }

        // filter excluded namespaces
        if(isset($ns['exclude'])){
            if($this->isInNamespace($ns['exclude'], $change['id'])) return false;
        }

        // check ACL
        $change['perms'] = auth_quickaclcheck($change['id']);
        if ($change['perms'] < AUTH_READ) return false;

        return $change;
    }

    /**
     * Check if page belongs to one of namespaces in the list
     */
    function isInNamespace($namespaces, $id) {
        foreach($namespaces as $ns){
            if((strpos($id, $ns . ':') === 0)) return true;
        }
        return false;
    }

    /**
     *
     */
    function renderPageList($changes, &$R, $flags) {
        $pagelist = @plugin_load('helper', 'pagelist');
        if($pagelist){
            $pagelist->setFlags($flags);
            $pagelist->startList();
            foreach($changes as $change){
                $pagelist->addPage(array('id' => $change['id']));
            }
            $R->doc .= $pagelist->finishList();
        }else{
            // Fallback to the simple list renderer
            $this->renderSimpleList($changes, $R);
        }
    }

    /**
     *
     */
    function renderSimpleList($changes, &$R) {
        $R->listu_open();
        foreach($changes as $change){
            $R->listitem_open(1);
            $R->listcontent_open();
            $R->internallink($change['id']);
            $R->cdata(' '.$change['sum']);
            $R->listcontent_close();
            $R->listitem_close();
        }
        $R->listu_close();
    }
}

//Setup VIM: ex: et ts=4 enc=utf-8 :
