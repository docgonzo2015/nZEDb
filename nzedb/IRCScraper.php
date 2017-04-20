<?php
namespace nzedb;

use app\models\Group;
use app\models\Predb as PreTab;
use nzedb\db\DB;
use nzedb\PreDb;

/**
 * Class IRCScraper
 */
class IRCScraper extends IRCClient
{
	/**
	 * Regex to ignore categories.
	 * @var string|bool
	 */
	protected $_categoryIgnoreRegex;

	/**
	 * Array of current pre info.
	 * @var array
	 * @access protected
	 */
	protected $_curPre;

	/**
	 * List of groups and their ID's
	 * @var array
	 * @access protected
	 */
	protected $_groupList;

	/**
	 * Array of ignored channels.
	 * @var array
	 */
	protected $_ignoredChannels;

	/**
	 * Is this pre nuked or un nuked?
	 * @var bool
	 * @access protected
	 */
	protected $_nuked;

	/**
	 * Result from model's (Predb) find('first')
	 *
	 * Doesn't matter to us if it is a Record or Document sub class.
	 *
	 * @var \lithium\data\Entity
	 * @access protected
	 */
	protected $_oldPre;

	/**
	 * @var \nzedb\db\DB
	 * @access protected
	 */
	protected $_pdo;

	/**
	 * Run this in silent mode (no text output).
	 * @var bool
	 * @access protected
	 */
	protected $_silent;

	/**
	 * Regex to ignore PRE titles.
	 * @var string|bool
	 */
	protected $_titleIgnoreRegex;

	/**
	 * Construct
	 *
	 * @param bool $silent Run this in silent mode (no text output).
	 * @param bool $debug  Turn on debug? Shows sent/received socket buffer messages.
	 *
	 * @access public
	 */
	public function __construct(&$silent = false, &$debug = false)
	{
		if (defined('SCRAPE_IRC_SOURCE_IGNORE')) {
			$this->_ignoredChannels = unserialize(SCRAPE_IRC_SOURCE_IGNORE);
		} else {
			$this->_ignoredChannels = [
				'#a.b.cd.image'               => false,
				'#a.b.console.ps3'            => false,
				'#a.b.dvd'                    => false,
				'#a.b.erotica'                => false,
				'#a.b.flac'                   => false,
				'#a.b.foreign'                => false,
				'#a.b.games.nintendods'       => false,
				'#a.b.inner-sanctum'          => false,
				'#a.b.moovee'                 => false,
				'#a.b.movies.divx'            => false,
				'#a.b.sony.psp'               => false,
				'#a.b.sounds.mp3.complete_cd' => false,
				'#a.b.teevee'                 => false,
				'#a.b.games.wii'              => false,
				'#a.b.warez'                  => false,
				'#a.b.games.xbox360'          => false,
				'#pre@corrupt'                => false,
				'#scnzb'                      => false,
				'#tvnzb'                      => false,
				'srrdb'                       => false
			];
		}

		$this->_categoryIgnoreRegex = false;
		if (defined('SCRAPE_IRC_CATEGORY_IGNORE') && SCRAPE_IRC_CATEGORY_IGNORE !== '') {
			$this->_categoryIgnoreRegex = SCRAPE_IRC_CATEGORY_IGNORE;
		}

		$this->_titleIgnoreRegex = false;
		if (defined('SCRAPE_IRC_TITLE_IGNORE') && SCRAPE_IRC_TITLE_IGNORE !== '') {
			$this->_titleIgnoreRegex = SCRAPE_IRC_TITLE_IGNORE;
		}

		$this->_pdo = new DB();
		$this->_groupList = [];
		$this->_silent = $silent;
		$this->_debug = $debug;
		$this->_resetPreVariables();
		$this->_startScraping();
	}

	public function __destruct()
	{
		parent::__destruct();
	}

	/**
	 * Main method for scraping.
	 *
	 * @access protected
	 */
	protected function _startScraping()
	{

		// Connect to IRC.
		if ($this->connect(SCRAPE_IRC_SERVER, SCRAPE_IRC_PORT, SCRAPE_IRC_TLS) === false) {
			exit (
				'Error connecting to (' .
				SCRAPE_IRC_SERVER .
				':' .
				SCRAPE_IRC_PORT .
				'). Please verify your server information and try again.' .
				PHP_EOL
			);
		}

		// Login to IRC.
		if ($this->login(SCRAPE_IRC_NICKNAME, SCRAPE_IRC_REALNAME, SCRAPE_IRC_USERNAME, SCRAPE_IRC_PASSWORD) === false) {
			exit('Error logging in to: (' .
				SCRAPE_IRC_SERVER . ':' . SCRAPE_IRC_PORT . ') nickname: (' . SCRAPE_IRC_NICKNAME .
				'). Verify your connection information, you might also be banned from this server or there might have been a connection issue.' .
				PHP_EOL
			);
		}

		// Join channels.
		$channels = defined('SCRAPE_IRC_CHANNELS') ? unserialize(SCRAPE_IRC_CHANNELS) : ['#nZEDbPRE' => null];
		$this->joinChannels($channels);

		if (!$this->_silent) {
			echo
				'[' .
				date('r') .
				'] [Scraping of IRC channels for (' .
				SCRAPE_IRC_SERVER .
				':' .
				SCRAPE_IRC_PORT .
				') (' .
				SCRAPE_IRC_NICKNAME .
				') started.]' .
				PHP_EOL;
		}

		// Scan incoming IRC messages.
		$this->readIncoming();
	}

	/**
	 * Process bot messages, insert/update PREs.
	 *
	 * @access protected
	 */
	protected function processChannelMessages()
	{
		if (preg_match(
			'/^(NEW|UPD|NUK): \[DT: (?P<time>.+?)\]\s?\[TT: (?P<title>.+?)\]\s?\[SC: (?P<source>.+?)\]\s?\[CT: (?P<category>.+?)\]\s?\[RQ: (?P<req>.+?)\]' .
			'\s?\[SZ: (?P<size>.+?)\]\s?\[FL: (?P<files>.+?)\]\s?(\[FN: (?P<filename>.+?)\]\s?)?(\[(?P<nuked>(UN|MOD|RE|OLD)?NUKED?): (?P<reason>.+?)\])?$/i',
			$this->_channelData['message'], $matches)) {

			if (isset($this->_ignoredChannels[$matches['source']]) && $this->_ignoredChannels[$matches['source']] === true) {
				return;
			}

			if ($this->_categoryIgnoreRegex !== false && preg_match((string)$this->_categoryIgnoreRegex, $matches['category'])) {
				return;
			}

			if ($this->_titleIgnoreRegex !== false && preg_match((string)$this->_titleIgnoreRegex, $matches['title'])) {
				return;
			}

			// TODO improve efficiency here by putting all into a single query. Also assign all
			// of the array in one assignment: $this->_curPre = $matches;
			$this->_curPre['predate'] = $this->_pdo->from_unixtime(strtotime($matches['time'] . ' UTC'));
			$this->_curPre['title'] = $matches['title'];
			$this->_curPre['source'] = $matches['source'];
			if ($matches['category'] !== 'N/A') {
				$this->_curPre['category'] = $matches['category'];
			}
			if ($matches['req'] !== 'N/A' && preg_match('/^(?P<req>\d+):(?P<group>.+)$/i', $matches['req'], $matches2)) {
				$this->_curPre['reqid'] = $matches2['req'];
				$this->_curPre['groups_id'] = $this->_getGroupID($matches2['group']);
			}
			if ($matches['size'] !== 'N/A') {
				$this->_curPre['size'] = $matches['size'];
			}
			if ($matches['files'] !== 'N/A') {
				$this->_curPre['files'] = substr($matches['files'], 0, 50);
			}

			if (isset($matches['filename']) && $matches['filename'] !== 'N/A') {
				$this->_curPre['filename'] = $matches['filename'];
			}

			if (isset($matches['nuked'])) {
				switch ($matches['nuked']) {
					case 'NUKED':
						$this->_curPre['nuked'] = PreDb::PRE_NUKED;
						break;
					case 'UNNUKED':
						$this->_curPre['nuked'] = PreDb::PRE_UNNUKED;
						break;
					case 'MODNUKED':
						$this->_curPre['nuked'] = PreDb::PRE_MODNUKE;
						break;
					case 'RENUKED':
						$this->_curPre['nuked'] = PreDb::PRE_RENUKED;
						break;
					case 'OLDNUKE':
						$this->_curPre['nuked'] = PreDb::PRE_OLDNUKE;
						break;
				}
				$this->_curPre['reason'] = (isset($matches['reason']) ? substr($matches['reason'], 0, 255) : '');
			}
			$this->_checkForDupe();
		}
	}

	/**
	 * Check if we already have the PRE, update if we have it, insert if not.
	 *
	 * @access protected
	 */
	protected function _checkForDupe()
	{
		$this->_oldPre = \app\models\Predb::find('first', [
			'conditions' => [ 'title' => $this->_curPre['title'] ],
			//'with' => 'Groups',
		]);

		if ($this->_oldPre === null) {
			$this->_insertNewPre();
		} else {
			$this->_updatePre();
		}

		/*
		$this->_oldPre = $this->_pdo->queryOneRow(sprintf('SELECT category, size FROM predb  WHERE title = %s', $this->_pdo->escapeString($this->_curPre['title'])));
		if ($this->_oldPre === false) {
			$this->_insertNewPre();
		} else {
			$this->_updatePre();
		}
		*/

		$this->_resetPreVariables();
	}

	/**
	 * Insert new PRE into the DB.
	 *
	 * @access protected
	 */
	protected function _insertNewPre()
	{
		// How would this be possible? We used the title to check for existing entry to update
		if (empty($this->_curPre['title'])) {
			return;
		}

		$data = ['title' => $this->_curPre['title']];

		if (!empty($this->_curPre['size'])) {
			$data['size'] = $this->_curPre['size'];
		}

		if (!empty($this->_curPre['source'])) {
			$data['source'] = $this->_curPre['source'];
		}

		if (!empty($this->_curPre['files'])) {
			$data['files'] = $this->_curPre['files'];
		}

		if (!empty($this->_curPre[''])) {
			$data['nukereason'] = $this->_curPre['reason'];
		}

		if (!empty($this->_curPre['reqid'])) {
			$data['requestid'] = $this->_curPre['reqid'];
		}

		if (!empty($this->_curPre['groups_id'])) {
			$data['groups_id'] = $this->_curPre['groups_id'];
		}

		if (!empty($this->_curPre['predate'])) {
			$data['predate'] = $this->_curPre['predate'];
		}

		if (!empty($this->_curPre['nuked'])) {
			$data['nuked'] = $this->_curPre['nuked'];
		}

		if (!empty($this->_curPre['filename'])) {
			$data['filename'] = $this->_curPre['filename'];
		}

		$this->_oldPre = PreTab::create($data);

		$this->_oldPre->save();

		/*
		$query = 'INSERT INTO predb (';

		$query .= (!empty($this->_curPre['size'])     ? 'size, '       : '');
		$query .= (!empty($this->_curPre['category']) ? 'category, '   : '');
		$query .= (!empty($this->_curPre['source'])   ? 'source, '     : '');
		$query .= (!empty($this->_curPre['reason'])   ? 'nukereason, ' : '');
		$query .= (!empty($this->_curPre['files'])    ? 'files, '      : '');
		$query .= (!empty($this->_curPre['reqid'])    ? 'requestid, '  : '');
		$query .= (!empty($this->_curPre['groups_id'])? 'groups_id, '  : '');
		$query .= (!empty($this->_curPre['nuked'])    ? 'nuked, '      : '');
		$query .= (!empty($this->_curPre['filename']) ? 'filename, '   : '');

		$query .= 'predate, title) VALUES (';

		$query .= (!empty($this->_curPre['size'])     ? $this->_pdo->escapeString($this->_curPre['size'])     . ', '   : '');
		$query .= (!empty($this->_curPre['category']) ? $this->_pdo->escapeString($this->_curPre['category']) . ', '   : '');
		$query .= (!empty($this->_curPre['source'])   ? $this->_pdo->escapeString($this->_curPre['source'])   . ', '   : '');
		$query .= (!empty($this->_curPre['reason'])   ? $this->_pdo->escapeString($this->_curPre['reason'])   . ', '   : '');
		$query .= (!empty($this->_curPre['files'])    ? $this->_pdo->escapeString($this->_curPre['files'])    . ', '   : '');
		$query .= (!empty($this->_curPre['reqid'])    ? $this->_curPre['reqid']                             . ', '   : '');
		$query .= (!empty($this->_curPre['groups_id']) ? $this->_curPre['groups_id'] . ', '   : '');
		$query .= (!empty($this->_curPre['nuked'])    ? $this->_curPre['nuked']                             . ', '   : '');
		$query .= (!empty($this->_curPre['filename']) ? $this->_pdo->escapeString($this->_curPre['filename']) . ', '   : '');
		$query .= (!empty($this->_curPre['predate'])  ? $this->_curPre['predate']                           . ', '   : 'NOW(), ');

		$query .= '%s)';

		$this->_pdo->ping(true);

		$this->_pdo->queryExec(
			sprintf(
				$query,
				$this->_pdo->escapeString($this->_curPre['title'])
			)
		);
		*/

		$this->_doEcho(true);
	}

	/**
	 * Updates PRE data in the DB.
	 *
	 * @access protected
	 */
	protected function _updatePre()
	{
		// How would this be possible? We used the title to check for existing entry to update
		if (empty($this->_curPre['title'])) {
			return;
		}

		if (!empty($this->_curPre['size']) && $this->_oldPre->size != $this->_curPre['size']) {
			$this->_oldPre->size = $this->_curPre['size'];
		}

		if (!empty($this->_curPre['source']) && $this->_oldPre->source != $this->_curPre['source']) {
			$this->_oldPre->source = $this->_curPre['source'];
		}

		if (!empty($this->_curPre['files']) && $this->_oldPre->files != $this->_curPre['files']) {
			$this->_oldPre->files = $this->_curPre['files'];
		}

		if (!empty($this->_curPre['']) && $this->_oldPre->nukereason != $this->_curPre['reason']) {
			$this->_oldPre->nukereason = $this->_curPre['reason'];
		}

		if (!empty($this->_curPre['reqid']) && $this->_oldPre->requestid != $this->_curPre['reqid']) {
			$this->_oldPre->requestid = $this->_curPre['reqid'];
		}

		if (!empty($this->_curPre['groups_id']) && $this->_oldPre->groups_id != $this->_curPre['groups_id']) {
			$this->_oldPre->groups_id = $this->_curPre['groups_id'];
		}

		if (!empty($this->_curPre['predate']) && $this->_oldPre->predate != $this->_curPre['predate']) {
			$this->_oldPre->predate = $this->_curPre['predate'];
		}

		if (!empty($this->_curPre['nuked']) && $this->_oldPre->nuked != $this->_curPre['nuked']) {
			$this->_oldPre->nuked = $this->_curPre['nuked'];
		}

		if (!empty($this->_curPre['filename']) && $this->_oldPre->filename != $this->_curPre['filename']) {
			$this->_oldPre->filename = $this->_curPre['filename'];
		}

		$this->_oldPre->save();
		/*
		$query = 'UPDATE predb SET ';

		$query .= (!empty($this->_curPre['size'])     ? 'size = '       . $this->_pdo->escapeString($this->_curPre['size'])     . ', ' : '');
		$query .= (!empty($this->_curPre['source'])   ? 'source = '     . $this->_pdo->escapeString($this->_curPre['source'])   . ', ' : '');
		$query .= (!empty($this->_curPre['files'])    ? 'files = '      . $this->_pdo->escapeString($this->_curPre['files'])    . ', ' : '');
		$query .= (!empty($this->_curPre['reason'])   ? 'nukereason = ' . $this->_pdo->escapeString($this->_curPre['reason'])   . ', ' : '');
		$query .= (!empty($this->_curPre['reqid'])    ? 'requestid = '  . $this->_curPre['reqid']                               . ', ' : '');
		$query .= (!empty($this->_curPre['groups_id']) ? 'groups_id = ' . $this->_curPre['groups_id']                           . ', ' : '');
		$query .= (!empty($this->_curPre['predate'])  ? 'predate = '    . $this->_curPre['predate']                             . ', ' : '');
		$query .= (!empty($this->_curPre['nuked'])    ? 'nuked = '      . $this->_curPre['nuked']                               . ', ' : '');
		$query .= (!empty($this->_curPre['filename']) ? 'filename = '   . $this->_pdo->escapeString($this->_curPre['filename']) . ', ' : '');
		$query .= (
			(empty($this->_oldPre['category']) && !empty($this->_curPre['category']))
				? 'category = ' . $this->_pdo->escapeString($this->_curPre['category']) . ', '
				: ''
		);

		if ($query === 'UPDATE predb SET ') {
			return;
		}

		$query .= 'title = ' . $this->_pdo->escapeString($this->_curPre['title']);
		$query .= ' WHERE title = ' . $this->_pdo->escapeString($this->_curPre['title']);

		$this->_pdo->ping(true);

		$this->_pdo->queryExec($query);
		*/

		$this->_doEcho(false);
	}

	/**
	 * Echo new or update pre to CLI.
	 *
	 * @param bool $new
	 *
	 * @access protected
	 */
	protected function _doEcho($new = true)
	{
		if (!$this->_silent) {

			$nukeString = '';
			if ($this->_nuked !== false) {
				switch ((int)$this->_curPre['nuked']) {
					case PreDb::PRE_NUKED:
						$nukeString = '[ NUKED ] ';
						break;
					case PreDb::PRE_UNNUKED:
						$nukeString = '[UNNUKED] ';
						break;
					case PreDb::PRE_MODNUKE:
						$nukeString = '[MODNUKE] ';
						break;
					case PreDb::PRE_OLDNUKE:
						$nukeString = '[OLDNUKE] ';
						break;
					case PreDb::PRE_RENUKED:
						$nukeString = '[RENUKED] ';
						break;
					default:
						break;
				}
				$nukeString .= '[' . $this->_curPre['reason'] . '] ';
			}

			echo
				'[' . date('r') . ($new ? '] [ Added Pre ] [' : '] [Updated Pre] [') .
				$this->_curPre['source'] . '] ' . $nukeString . '[' . $this->_curPre['title'] .
				']' . (!empty($this->_curPre['category']) ? ' [' . $this->_curPre['category'] . ']' : (!empty($this->_oldPre->category) ? ' [' . $this->_oldPre->category . ']' : '')
				) . (!empty($this->_curPre['size']) ? ' [' . $this->_curPre['size'] . ']' : '') .
				PHP_EOL;
		}
	}

	/**
	 * Get a group ID for a group name.
	 *
	 * @param string $groupName
	 *
	 * @return mixed
	 *
	 * @access protected
	 */
	protected function _getGroupID($groupName)
	{
		if (!isset($this->_groupList[$groupName])) {
			$group = Group::findIdFromName($groupName, [
				'create'	=> true,
				'description'	=> 'Added by IRCScraper.',
			]);

			if ($group === null) {
				$this->_groupList[$groupName] = false;
			} else {
				$this->_groupList[$groupName] = $group->id;
			}
		}

		return $this->_groupList[$groupName];
	}

	/**
	 * After updating or inserting new PRE, reset these.
	 *
	 * @access protected
	 */
	protected function _resetPreVariables()
	{
		$this->_nuked = false;
		$this->_oldPre = [];
		$this->_curPre =
			[
				'title'    => '',
				'size'     => '',
				'predate'  => '',
				'category' => '',
				'source'   => '',
				'groups_id'=> '',
				'reqid'    => '',
				'nuked'    => '',
				'reason'   => '',
				'files'    => '',
				'filename' => ''
			];
	}
}
