<?php
/*
 * @copyright 2016-2022 Matias De lellis <mati86dl@gmail.com>
 *
 * @author 2016 Matias De lellis <mati86dl@gmail.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\QuickNotes\Service;

use OCA\QuickNotes\Db\Attach;
use OCA\QuickNotes\Db\AttachMapper;

use OCA\QuickNotes\Db\Color;
use OCA\QuickNotes\Db\ColorMapper;

use OCA\QuickNotes\Db\Note;
use OCA\QuickNotes\Db\NoteMapper;

use OCA\QuickNotes\Db\NoteTag;
use OCA\QuickNotes\Db\NoteTagMapper;

use OCA\QuickNotes\Db\NoteShare;
use OCA\QuickNotes\Db\NoteShareMapper;

use OCA\QuickNotes\Db\Tag;
use OCA\QuickNotes\Db\TagMapper;

use OCA\QuickNotes\Service\FileService;
use OCA\QuickNotes\Service\SettingsService;

use OCP\AppFramework\Db\DoesNotExistException;

use OCP\IUserManager;

class NoteService {

	private $notemapper;
	private $notetagmapper;
	private $colormapper;
	private $noteShareMapper;
	private $attachMapper;
	private $tagmapper;
	private $fileService;
	private $settingsService;

	/** @var IUserManager */
	private $userManager;

	public function __construct(NoteMapper      $notemapper,
	                            NoteTagMapper   $notetagmapper,
	                            NoteShareMapper $noteShareMapper,
	                            ColorMapper     $colormapper,
	                            AttachMapper    $attachMapper,
	                            TagMapper       $tagmapper,
	                            FileService     $fileService,
	                            SettingsService $settingsService,
	                            IUserManager    $userManager)
	{
		$this->notemapper      = $notemapper;
		$this->notetagmapper   = $notetagmapper;
		$this->colormapper     = $colormapper;
		$this->noteShareMapper = $noteShareMapper;
		$this->attachMapper    = $attachMapper;
		$this->tagmapper       = $tagmapper;
		$this->fileService     = $fileService;
		$this->settingsService = $settingsService;
		$this->userManager     = $userManager;
	}

	/**
	 * @NoAdminRequired
	 */
	 public function getAll(string $userId): array {
		$notes = $this->notemapper->findAll($userId);

		// Set shares with others.
		foreach($notes as $note) {
			$sharedWith = [];
			$sharedEntries = $this->noteShareMapper->getSharesForNote($note->getId());
			foreach ($sharedEntries as $sharedEntry) {
				$sharedUid = $sharedEntry->getSharedUser();
				$user = $this->userManager->get($sharedUid);
				if (!$user) {
					// TODO: Debug that...
					continue;
				}
				$sharedEntry->setDisplayName($user->getDisplayName());
				$sharedWith[] = $sharedEntry;
			}
			$note->setSharedWith($sharedWith);
		}

		// Get shares from others.
		$shares = [];
		$sharedEntries = $this->noteShareMapper->findForUser($userId);
		foreach($sharedEntries as $sharedEntry) {
			$sharedNote = $this->notemapper->findShared($sharedEntry->getNoteId());

			$uid = $sharedNote->getUserId();
			$sharedEntry->setUserId($uid);
			$user = $this->userManager->get($uid);
			if (!$user) {
				// TODO: Debug that...
				continue;
			}
			$sharedEntry->setDisplayName($user->getDisplayName());
			$sharedNote->setSharedBy([$sharedEntry]);
			$shares[] = $sharedNote;
		}

		// Attahch shared notes from others to same response
		$notes = array_merge($notes, $shares);

		// Set tags to response.
		foreach($notes as $note) {
			$note->setTags($this->tagmapper->getTagsForNote($userId, $note->getId()));
		}

		// Insert color to response
		foreach ($notes as $note) {
			$note->setColor($this->colormapper->find($note->getColorId())->getColor());
		}

		// Insert pin to response
		foreach ($notes as $note) {
			$note->setIsPinned($note->getPinned() ? true : false);
		}

		// Insert attachts to response.
		foreach ($notes as $note) {
			$rAttachts = [];
			$attachts = $this->attachMapper->findFromNote($note->getUserId(), $note->getId());
			foreach ($attachts as $attach) {
				$previewUrl = $this->fileService->getPreviewUrl($attach->getFileId(), 512);
				if (is_null($previewUrl))
					continue;

				$redirectUrl = $this->fileService->getRedirectToFileUrl($attach->getFileId());
				if (is_null($redirectUrl))
					continue;

				$deepLinkUrl = $this->fileService->getDeepLinkUrl($attach->getFileId());
				if (is_null($deepLinkUrl))
					continue;

				$attach->setPreviewUrl($previewUrl);
				$attach->setRedirectUrl($redirectUrl);
				$attach->setDeepLinkUrl($deepLinkUrl);

				$rAttachts[] = $attach;
			}
			$note->setAttachts($rAttachts);
		}

		return $notes;
	}

	/**
	 * @param string $userId
	 * @param int $id
	 */
	public function get(string $userId, int $id): ?Note {
		try {
			return $this->notemapper->find($id, $userId);
		} catch(DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * @param string $userId
	 * @param string $title
	 * @param string $content
	 * @param string $color optional color.
	 * @param bool   $isPinned optional if note must be pinned
	 * @param array  $sharedWith optional list of shares
	 * @param array  $tags optional list of tags
	 * @param array  $attachments optional list of attachments
	 */
	public function create(string $userId,
	                       string $title,
	                       string $content,
	                       string $color = null,
	                       bool   $isPinned = false,
	                       array  $sharedWith = [],
	                       array  $tags = [],
	                       array  $attachments = []): ?Note
	{
		if (is_null($color)) {
			$color = $this->settingsService->getColorForNewNotes();
		}

		// Get color or append it
		if ($this->colormapper->colorExists($color)) {
			$hcolor = $this->colormapper->findByColor($color);
		} else {
			$hcolor = new Color();
			$hcolor->setColor($color);
			$hcolor = $this->colormapper->insert($hcolor);
		}

		// Create note and insert it
		$note = new Note();

		$note->setTitle($title);
		$note->setContent($content);
		$note->setPinned($isPinned ? 1 : 0);
		$note->setTimestamp(time());
		$note->setColorId($hcolor->id);
		$note->setUserId($userId);

		$newNote = $this->notemapper->insert($note);

		// TODO: Insert optional shares, tags and attachments.

		// Add new tags and update relations.
		foreach ($tags as $tag) {
			if (!$this->tagmapper->tagExists($userId, $tag['name'])) {
				$htag = new Tag();
				$htag->setName($tag['name']);
				$htag->setUserId($userId);
				$htag = $this->tagmapper->insert($htag);
			}
			else {
				$htag = $this->tagmapper->getTag($userId, $tag['name']);
			}

			if (!$this->notetagmapper->noteTagExists($userId, $newNote->getId(), $htag->getId())) {
				$noteTag = new NoteTag();
				$noteTag->setNoteId($newNote->getId());
				$noteTag->setTagId($htag->getId());
				$noteTag->setUserId($userId);
				$this->notetagmapper->insert($noteTag);
			}
		}

		// Insert true color pin and tags to response
		$newNote->setColor($hcolor->getColor());
		$newNote->setIsPinned($isPinned);
		$newNote->setTags($this->tagmapper->getTagsForNote($userId, $newNote->getId()));
		$newNote->setAttachts([]);

		return $newNote;
	}

	/**
	 * @param string userId
	 * @param int    $id
	 * @param string $title
	 * @param string $content
	 * @param string $color
	 * @param bool   $isPinned
	 * @param array  $tags
	 * @param array  $attachments
	 * @param array  $sharedWith
	 */
	public function update(string $userId,
	                       int    $id,
	                       string $title,
	                       string $content,
	                       string $color,
	                       bool   $isPinned,
	                       array  $tags,
	                       array  $attachments,
	                       array  $sharedWith): ?Note
	{
		// Get current Note and Color.
		$note = $this->get($userId, $id);
		if (is_null($note))
			return null;

		$oldcolorid = $note->getColorId();

		// Get new Color or append it.
		if ($this->colormapper->colorExists($color)) {
			$hcolor = $this->colormapper->findByColor($color);
		} else {
			$hcolor = new Color();
			$hcolor->setColor($color);
			$hcolor = $this->colormapper->insert($hcolor);
		}

		// Delete old attachts
		$dbAttachts = $this->attachMapper->findFromNote($userId, $id);
		foreach ($dbAttachts as $dbAttach) {
			$delete = true;
			foreach ($attachments as $attach) {
				if ($dbAttach->getFileId() === $attach['file_id']) {
					$delete = false;
					break;
				}
			}
			if ($delete) {
				$this->attachMapper->delete($dbAttach);
			}
		}

		// Add new attachts
		foreach ($attachments as $attach) {
			if (!$this->attachMapper->fileAttachExists($userId, $id, $attach['file_id'])) {
				$hAttach = new Attach();
				$hAttach->setUserId($userId);
				$hAttach->setNoteId($id);
				$hAttach->setFileId($attach['file_id']);
				$hAttach->setCreatedAt(time());
				$this->attachMapper->insert($hAttach);
			}
		}

		// Delete old shares
		$dbShares = $this->noteShareMapper->getSharesForNote($id);
		foreach ($dbShares as $dbShare) {
			$delete = true;
			foreach ($sharedWith as $share) {
				if ($dbShare->getSharedUser() === $share['id']) {
					$delete = false;
					break;
				}
			}
			if ($delete) {
				$this->noteShareMapper->delete($dbShare);
			}
		}

		// Add new shares
		foreach ($sharedWith as $share) {
			if (!$this->noteShareMapper->existsByNoteAndUser($id, $share['id'])) {
				$hShare = new NoteShare();
				$hShare->setNoteId($id);
				$hShare->setSharedUser($share['id']);
				$this->noteShareMapper->insert($hShare);
			}
		}

		// Delete old tag relations
		$dbTags = $this->tagmapper->getTagsForNote($userId, $id);
		foreach ($dbTags as $dbTag) {
			$delete = true;
			foreach ($tags as $tag) {
				if ($dbTag->getId() === $tag['id']) {
					$delete = false;
					break;
				}
			}
			if ($delete) {
				$hnotetag = $this->notetagmapper->findNoteTag($userId, $id, $dbTag->getId());
				$this->notetagmapper->delete($hnotetag);
			}
		}

		// Add new tags and update relations.
		foreach ($tags as $tag) {
			if (!$this->tagmapper->tagExists($userId, $tag['name'])) {
				$htag = new Tag();
				$htag->setName($tag['name']);
				$htag->setUserId($userId);
				$htag = $this->tagmapper->insert($htag);
			}
			else {
				$htag = $this->tagmapper->getTag($userId, $tag['name']);
			}

			if (!$this->notetagmapper->noteTagExists($userId, $id, $htag->getId())) {
				$noteTag = new NoteTag();
				$noteTag->setNoteId($id);
				$noteTag->setTagId($htag->getId());
				$noteTag->setUserId($userId);
				$this->notetagmapper->insert($noteTag);
			}
		}

		// Set new info on Note
		$note->setTitle($title);
		$note->setContent($content);
		$note->setPinned($isPinned ? 1 : 0);
		$note->setTimestamp(time());
		$note->setColorId($hcolor->id);

		// Update note.
		$newnote = $this->notemapper->update($note);

		// Insert true color and pin to response
		$newnote->setColor($hcolor->getColor());
		$newnote->setIsPinned($isPinned);

		// Fill new tags
		$newnote->setTags($this->tagmapper->getTagsForNote($userId, $newnote->getId()));

		// Fill attachts to response
		$attachts = $this->attachMapper->findFromNote($userId, $newnote->getId());
		foreach ($attachts as $attach) {
			$attach->setPreviewUrl($this->fileService->getPreviewUrl($attach->getFileId(), 512));
			$attach->setRedirectUrl($this->fileService->getRedirectToFileUrl($attach->getFileId()));
			$attach->setDeepLinkUrl($this->fileService->getDeepLinkUrl($attach->getFileId()));
		}
		$newnote->setAttachts($attachts);

		// Fill shared with with others
		$sharedWith = [];
		$sharedEntries = $this->noteShareMapper->getSharesForNote($note->getId());
		foreach ($sharedEntries as $sharedEntry) {
			$sharedUid = $sharedEntry->getSharedUser();
			$user = $this->userManager->get($sharedUid);
			if (!$user) {
				// TODO: Debug that...
				continue;
			}
			$sharedEntry->setDisplayName($user->getDisplayName());

			$sharedWith[] = $sharedEntry;
		}
		$note->setSharedWith($sharedWith);

		//  Remove old color if necessary
		if (($oldcolorid !== $hcolor->getId()) &&
		    (!$this->notemapper->colorIdCount($oldcolorid))) {
			$oldcolor = $this->colormapper->find($oldcolorid);
			$this->colormapper->delete($oldcolor);
		}

		// Purge orphan tags.
		$this->tagmapper->dropOld();

		return $newnote;
	}

	/**
	 * @param string $userId
	 * @param int $id
	 *
	 * @return void
	 */
	public function destroy (string $userId, int $id) {
		// Get Note and Color
		try {
			$note = $this->notemapper->find($id, $userId);
		} catch(DoesNotExistException $e) {
			return;
		}
		$oldcolorid = $note->getColorId();

		$this->noteShareMapper->deleteByNoteId($note->getId());

		// Delete note.
		$this->notemapper->delete($note);

		// Delete Color if necessary
		if (!$this->notemapper->colorIdCount($oldcolorid)) {
			$oldcolor = $this->colormapper->find($oldcolorid);
			$this->colormapper->delete($oldcolor);
		}

		$attachts = $this->attachMapper->findFromNote($userId, $id);
		foreach ($attachts as $attach) {
			$this->attachMapper->delete($attach);
		}
	}

}
