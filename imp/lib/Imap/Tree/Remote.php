<?php
/**
 * Copyright 2013 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.horde.org/licenses/gpl.
 *
 * @category  Horde
 * @copyright 2013 Horde LLC
 * @license   http://www.horde.org/licenses/gpl GPL
 * @package   IMP
 */

/**
 * Extends the base object to support creating tree from a remote account.
 *
 * @author    Michael Slusarz <slusarz@horde.org>
 * @category  Horde
 * @copyright 2013 Horde LLC
 * @license   http://www.horde.org/licenses/gpl GPL
 * @package   IMP
 */
class IMP_Imap_Tree_Remote extends IMP_Imap_Tree
{
    /**
     * Use this IMAP identifier key instead of the base object.
     *
     * @var string
     */
    protected $_imapkey;

    /**
     * Constructor.
     *
     * @param array $opts  Additional options:
     * <pre>
     *   - imapkey: (string) The IMAP identifier to use instead of the base
     *              IMAP object.
     * </pre>
     */
    public function __construct(array $opts = array())
    {
        $this->_imapkey = $opts['imapkey'];

        $this->init();
    }

    /**
     */
    protected function _initRemote()
    {
    }

    /**
     */
    protected function _initVirtualFolders()
    {
    }

    /**
     */
    protected function _makeElt($name, $attributes = 0)
    {
        $name = $this->_imapkey . "\0" . $name;
        return parent::_makeElt($name, $attributes);
    }

    /**
     * Return the IMP_Imap object to use for this instance.
     *
     * @return IMP_Imap  IMP_Imap object.
     */
    protected function _getImpImapOb()
    {
        return $GLOBALS['injector']->getInstance('IMP_Factory_Imap')->create($this->_imapkey);
    }

    /**
     */
    protected function _initPollList()
    {
        if (!isset($this->_cache['poll'])) {
            $this->_cache['poll'] = new Horde_Support_Stub();
        }
    }

    /* Serializable methods. */

    /**
     */
    public function serialize()
    {
        return serialize(array(
            parent::serialize(),
            $this->_imapkey
        ));
    }

    /**
     * @throws Exception
     */
    public function unserialize($data)
    {
        $data = @unserialize($data);
        if (!is_array($data)) {
            throw new Exception('Cache version change');
        }

        parent::unserialize($data[0]);
        $this->_imapkey = $data[1];
    }

}
