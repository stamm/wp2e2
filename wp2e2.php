<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(-1);

/**
 * Класс импорта статей из wordpress в Эгею
 * User: Zagirov Rustam <rustam@zagirov.name>
 */

Class Parse {
	public $wpHost;
	public $wpDb;
	public $wpUser;
	public $wpPassword;
	public $wpPrefix='wp_';

	public $e2Host;
	public $e2Db;
	public $e2User;
	public $e2Password;
	public $e2Prefix='e2_';
	
	// Название поля URL тега
	public $e2KeywordsUrlName='URLName';


	/**
	 * Собственно сама работа
	 * @return void
	 */
	function go()
	{
		try {
			$dbWp = new PDO('mysql:dbname=' . $this->wpDb . ';host=' . $this->wpHost, $this->wpUser, $this->wpPassword);
			$dbE2 = new PDO('mysql:dbname=' . $this->e2Db . ';host=' . $this->e2Host, $this->e2User, $this->e2Password);

			$dbWp->query('SET NAMES utf8');
			$dbE2->query('SET NAMES utf8');

			// Посты
			$sSql = 'SELECT id, post_date, post_date_gmt, post_content, post_title, post_name, post_type, post_modified, post_status, comment_status
			FROM `' . $this->wpPrefix . 'posts`
			WHERE post_type = "post"
			AND post_status IN ("publish", "draft")
			ORDER BY id ASC';
			$aPosts = array();
			foreach ($dbWp->query($sSql) as $aPost)
			{
				$aPosts[$aPost['id']] = $aPost;
			}
			unset($aPostsSql);
			echo 'Found ' . count($aPosts) . ' posts';

			// Тэги
			$sSql = 'SELECT r.object_id as post_id, t.name
			FROM `' . $this->wpPrefix . 'term_relationships` AS r
			JOIN `' . $this->wpPrefix . 'term_taxonomy` AS tt ON r.term_taxonomy_id = tt.term_id AND tt.taxonomy="post_tag"
				JOIN `' . $this->wpPrefix . 'terms` AS t ON t.term_id = tt.term_id
				ORDER BY post_id';
			
			foreach ($dbWp->query($sSql) as $aTag)
			{
				// Пропускаем тэг, если нет поста
				if ( ! isset($aPosts[$aTag['post_id']]))
				{
					continue;
				}
				if ( ! isset($aPosts[$aTag['post_id']]['tags']))
				{
					$aPosts[$aTag['post_id']]['tags'] = array();
				}
				$aPosts[$aTag['post_id']]['tags'][] = $aTag['name'];
			}
			unset($aTagsSql);

			// Комментарии
			$sSql = 'SELECT comment_post_ID AS post_id,
					comment_author AS author,
					comment_author_email AS email,
					comment_author_url AS url,
					comment_author_IP AS ip,
					comment_date AS create_time,
					comment_content AS content
				FROM `' . $this->wpPrefix . 'comments`
				WHERE comment_approved = 1
				AND comment_author_IP != "127.0.0.1"';
			foreach ($dbWp->query($sSql) as $aComment)
			{
				// Пропускаем коммент, если нет поста
				if ( ! isset($aPosts[$aComment['post_id']]))
				{
					continue;
				}
				if ( ! isset($aPosts[$aComment['post_id']]['comments']))
				{
					$aPosts[$aComment['post_id']]['comments'] = array();
				}

				$aPosts[$aComment['post_id']]['comments'][] = $aComment;
			}

			foreach($aPosts as $aPost)
			{
				$aSql = array(
					'Title' => $aPost['post_title'],
					'OriginalAlias' => $aPost['post_name'],
					'Text' => $aPost['post_content'],
					'IsPublished' => $aPost['post_status'] == 'publish',
					'IsCommentable' => $aPost['comment_status'] == 'open',
					'Stamp' => strtotime($aPost['post_date']),
					'LastModified' => strtotime($aPost['post_modified']),
					'FormatterID' => 'raw',
					'Offset' => (strtotime($aPost['post_date']) - strtotime($aPost['post_date_gmt'])),
				);
				$sSql = 'INSERT INTO `' . $this->e2Prefix . 'Notes` (' . implode(',', array_keys($aSql)) . ')
				VALUES (' . implode(',', array_map(array($dbE2, 'quote'), $aSql)) . ')';

				$dbE2->exec($sSql);
				$iPostId = $dbE2->lastInsertId();
				echo 'post_id: ' . $iPostId;

				if (isset($aPost['tags']))
				{
					foreach ($aPost['tags'] as $sTag)
					{
						$sth = $dbE2->prepare('SELECT id FROM `' . $this->e2Prefix . 'Keywords` WHERE LOWER(keyword) = LOWER(' . $dbE2->quote($sTag) . ')');
						$sth->execute();
						$iTagId = $sth->fetchColumn();
						if ( ! $iTagId)
						{
							$sSql = 'INSERT INTO `' . $this->e2Prefix . 'Keywords`(Keyword, ' . $this->e2KeywordsUrlName . ') VALUES (' . $dbE2->quote($sTag) . ',' . $dbE2->quote($sTag) . ')';
							$dbE2->exec($sSql);
							$iTagId = $dbE2->lastInsertId();
						}
						$sSql = 'INSERT INTO `' . $this->e2Prefix . 'NotesKeywords`(NoteID, KeywordID) VALUES (' . $dbE2->quote($iPostId) . ',' . $dbE2->quote($iTagId) . ')';
						$dbE2->exec($sSql);
					}
				}

				if (isset($aPost['comments']) && is_array($aPost['comments']))
				{
					foreach ($aPost['comments'] as $aComment)
					{
						$aSql = array(
							'NoteID' => $iPostId,
							'AuthorName' => $aComment['author'],
							'AuthorEmail' => $aComment['email'],
							'Text' => $aComment['content'],
							'Stamp' => strtotime($aComment['create_time']),
							'LastModified' => strtotime($aComment['create_time']),
							'IP' => $aComment['ip'],
						);

						$sSql = 'INSERT INTO `' . $this->e2Prefix . 'Comments` (' . implode(',', array_map(array($this, 'quoteTable'), array_keys($aSql))) . ')
						VALUES (' . implode(',', array_map(array($dbE2, 'quote'), $aSql)) . ')';
						$dbE2->query($sSql);
					}
				}
			}
		} catch (PDOException $e) {
			echo 'Connection failed: ' . $e->getMessage();
		}
	}

	function quoteTable($s)
	{
		return '`' . $s . '`';
	}
}


$parse = new Parse;

$parse->wpHost = 'localhost';
$parse->wpDb = '';
$parse->wpUser = '';
$parse->wpPassword = '';

$parse->e2Host = 'localhost';
$parse->e2Db = '';
$parse->e2User = '';
$parse->e2Password = '';
// Если Эгея >= 2.5 версии, то раскоментируйте эту строку
// $parse->e2Prefix = 'e2Blog';

// Если Эгея >= 2.7 версии, то раскоментируйте эту строку
// $parse->e2KeywordsUrlName = 'OriginalAlias';

$parse->go();
