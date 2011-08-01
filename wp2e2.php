<?php
/**
 * Created by JetBrains PhpStorm.
 * User: Zagirov Rustam <rustam@zagirov.name>
 * Date: 10.04.11
 * Time: 20:58
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
public $e2Prefix='e2Blog';


/**
 * Парсинг статей из wordpress
 * @return void
 */
	function go()
	{
		try {
			$dbWp = new PDO('mysql:dbname=' . $this->wpDb . ';host=' . $this->wpHost, $this->wpUser, $this->wpUser);
			$dbE2 = new PDO('mysql:dbname=' . $this->e2Db . ';host=' . $this->e2Host, $this->e2User, $this->e2User);

			$dbWp->query('SET NAMES cp1251');
			$dbE2->query('SET NAMES cp1251');

			// Посты
			$sSql = 'SELECT id, post_date, post_content, post_title, post_name, post_type, post_modified
			FROM `' . $this->wpPrefix . 'posts`
			WHERE post_type IN ("post", "draft")
			AND post_status = "publish"
			ORDER BY id ASC';
			$aPosts = array();
			foreach ($dbWp->query($sSql) as $aPost)
			{
				$aPosts[$aPost['id']] = $aPost;
			}
			unset($aPostsSql);

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
					'URLName' => $aPost['post_name'],
					'Text' => $aPost['post_content'],
					'IsPublished' => $aPost['post_type'] == 'post',
					'Stamp' => 	strtotime($aPost['post_date']),
					'LastModified' => strtotime($aPost['post_modified']),
				);
				$sSql = 'INSERT INTO `' . $this->e2Prefix . 'Notes` (' . implode(',', array_keys($aSql)) . ')
				VALUES (' . implode(',', array_map(array($dbE2, 'quote'), $aSql)) . ')';

				$dbE2->exec($sSql);
				$iPostId = $dbE2->lastInsertId();

				if (isset($aPost['tags']))
				{
					foreach ($aPost['tags'] as $sTag)
					{
						$sth = $dbE2->prepare('SELECT id FROM `' . $this->e2Prefix . 'Keywords` WHERE LOWER(keyword) = LOWER(' . $dbE2->quote($sTag) . ')');
						$sth->execute();
						$iTagId = $sth->fetchColumn();
						if ( ! $iTagId)
						{
							$sSql = 'INSERT INTO `' . $this->e2Prefix . 'Keywords`(Keyword, URLName) VALUES (' . $dbE2->quote($sTag) . ',' . $dbE2->quote($sTag) . ')';
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
							'Stamp' => 	strtotime($aComment['create_time']),
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
$parse->e2Host = 'localhost';
$parse->e2Db = 'all.zagirov.name';
$parse->e2User = 'e2';
$parse->e2Password = 'e2';
$parse->wpHost = 'localhost';
$parse->wpDb = 'wordpress';
$parse->wpUser = 'e2';
$parse->wpPassword = 'e2';

$parse->go();