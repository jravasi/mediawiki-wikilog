<?php
/**
 * MediaWiki Wikilog extension
 * Copyright © 2008, 2009 Juliano F. Ravasi
 * http://www.mediawiki.org/wiki/Extension:Wikilog
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 59 Temple Place - Suite 330, Boston, MA 02111-1307, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

/**
 * @addtogroup Extensions
 * @author Juliano F. Ravasi < dev juliano info >
 */

if ( !defined( 'MEDIAWIKI' ) )
	die();

/**
 * General wikilog hooks.
 */
class WikilogHooks
{
	/**
	 * ArticleEditUpdates hook handler function.
	 * Performs post-edit updates if article is a wikilog article.
	 */
	static function ArticleEditUpdates( &$article, &$editInfo, $changed ) {
		# When editing through MW interface, article is derived from
		# WikilogCommentsPage. In this case, update the comment object.
		if ( $article instanceof WikilogCommentsPage && $changed ) {
			$cmt =& $article->mSingleComment;
			if ( $cmt && !$cmt->isTextChanged() && $changed ) {
				$cmt->mUpdated = wfTimestamp( TS_MW );
				$cmt->saveComment();
			}
		}

		$title = $article->getTitle();
		$wi = Wikilog::getWikilogInfo( $title );

		# Do nothing if not a wikilog article.
		if ( !$wi ) return true;

		if ( $title->isTalkPage() ) {
			# ::WikilogCommentsPage::
			# Invalidate cache of wikilog item page.
			if ( $wi->getItemTitle()->exists() ) {
				$wi->getItemTitle()->invalidateCache();
				$wi->getItemTitle()->purgeSquid();
			}
		} else if ( $wi->isItem() ) {
			# ::WikilogItemPage::
			$item = WikilogItem::newFromInfo( $wi );
			if ( !$item ) {
				$item = new WikilogItem();
			}

			$item->mName = $wi->getItemName();
			$item->mTitle = $wi->getItemTitle();
			$item->mParentName = $wi->getName();
			$item->mParentTitle = $wi->getTitle();
			$item->mParent = $item->mParentTitle->getArticleId();

			$item->resetID( $article->getId() );

			# Check if we have any wikilog metadata available.
			if ( isset( $editInfo->output->mExtWikilog ) ) {
				$output = $editInfo->output->mExtWikilog;

				# Update entry in wikilog_posts table.
				# Entries in wikilog_authors and wikilog_tags are updated
				# during LinksUpdate process.
				$item->mPublish = $output->mPublish;
				$item->mUpdated = wfTimestamp( TS_MW );
				$item->mPubDate = $output->mPublish ? $output->mPubDate : $item->mUpdated;
				$item->mAuthors = $output->mAuthors;
				$item->mTags    = $output->mTags;
				$item->saveData();
			} else {
				# Remove entry from tables. Entries in wikilog_authors and
				# wikilog_tags are removed during LinksUpdate process.
				$item->deleteData();
			}

			# Invalidate cache of parent wikilog page.
			WikilogUtils::updateWikilog( $wi->getTitle() );
		} else {
			# ::WikilogMainPage::
			$dbw = wfGetDB( DB_MASTER );
			$id = $article->getId();

			# Check if we have any wikilog metadata available.
			if ( isset( $editInfo->output->mExtWikilog ) ) {
				$output = $editInfo->output->mExtWikilog;
				$subtitle = $output->mSummary
					? array( 'html', $output->mSummary )
					: '';

				# Update entry in wikilog_wikilogs table. Entries in
				# wikilog_authors and wikilog_tags are updated during
				# LinksUpdate process.
				$dbw->replace(
					'wikilog_wikilogs',
					'wlw_page',
					array(
						'wlw_page' => $id,
						'wlw_subtitle' => serialize( $subtitle ),
						'wlw_icon' => $output->mIcon ? $output->mIcon->getDBKey() : '',
						'wlw_logo' => $output->mLogo ? $output->mLogo->getDBKey() : '',
						'wlw_authors' => serialize( $output->mAuthors ),
						'wlw_updated' => $dbw->timestamp()
					),
					__METHOD__
				);
			} else {
				# Remove entry from tables. Entries in wikilog_authors and
				# wikilog_tags are removed during LinksUpdate process.
				$dbw->delete( 'wikilog_wikilogs', array( 'wlw_page' => $id ), __METHOD__ );
			}
		}

		return true;
	}

	/**
	 * ArticleDeleteComplete hook handler function.
	 * Purges wikilog metadata when an article is deleted.
	 */
	static function ArticleDeleteComplete( &$article, &$user, $reason, $id ) {
		# Deleting comment through MW interface.
		if ( $article instanceof WikilogCommentsPage ) {
			$cmt =& $article->mSingleComment;
			if ( $cmt ) {
				$cmt->mStatus = WikilogComment::S_DELETED;
				$cmt->saveComment();
			}
		}

		# Retrieve wikilog information.
		$wi = Wikilog::getWikilogInfo( $article->getTitle() );

		# Take special procedures if it is a wikilog page.
		if ( $wi ) {
			$dbw = wfGetDB( DB_MASTER );

			if ( $wi->isItem() ) {
				# Delete table entries.
				$dbw->delete( 'wikilog_posts',    array( 'wlp_page'   => $id ) );
				$dbw->delete( 'wikilog_comments', array( 'wlc_parent' => $id ) );
				$dbw->delete( 'wikilog_authors',  array( 'wla_page'   => $id ) );
				$dbw->delete( 'wikilog_tags',     array( 'wlt_page'   => $id ) );
				$dbw->delete( 'wikilog_comments', array( 'wlc_post'   => $id ) );

				# Invalidate cache of parent wikilog page.
				WikilogUtils::updateWikilog( $wi->getTitle() );
			} else {
				# Delete table entries.
				$dbw->delete( 'wikilog_wikilogs', array( 'wlw_page'   => $id ) );
				$dbw->delete( 'wikilog_posts',    array( 'wlp_parent' => $id ) );
				$dbw->delete( 'wikilog_authors',  array( 'wla_page'   => $id ) );
				$dbw->delete( 'wikilog_tags',     array( 'wlt_page'   => $id ) );
			}
		}

		return true;
	}

	/**
	 * TitleMoveComplete hook handler function.
	 * Handles moving articles to and from wikilog namespaces.
	 */
	static function TitleMoveComplete( &$oldtitle, &$newtitle, &$user, $pageid, $redirid ) {
		global $wgWikilogNamespaces;

		# Check if it was or is now in a wikilog namespace.
		$oldwl = in_array( ( $oldns = $oldtitle->getNamespace() ), $wgWikilogNamespaces );
		$newwl = in_array( ( $newns = $newtitle->getNamespace() ), $wgWikilogNamespaces );

		if ( $oldwl && $newwl ) {
			# Moving title between wikilog namespaces.
			# Update wikilog data.
			wfDebug( __METHOD__ . ": Moving title between wikilog namespaces " .
				"($oldns, $newns). Updating wikilog data.\n" );

			$wi = Wikilog::getWikilogInfo( $newtitle );
			$item = WikilogItem::newFromID( $pageid );
			if ( $wi && $wi->isItem() && !$wi->isTalk() && $item ) {
				$item->mName = $wi->getItemName();
				$item->mTitle = $wi->getItemTitle();
				$item->mParentName = $wi->getName();
				$item->mParentTitle = $wi->getTitle();
				$item->mParent = $item->mParentTitle->getArticleId();
				$item->saveData();
			}
		} else if ( $newwl ) {
			# Moving from normal namespace to wikilog namespace.
			# Create wikilog data.
			wfDebug( __METHOD__ . ": Moving from another namespace to wikilog " .
				"namespace ($oldns, $newns). Creating wikilog data.\n" );
			# FIXME: This needs a reparse of the wiki text in order to
			# populate wikilog metadata. Or forbid this action.
		} else if ( $oldwl ) {
			# Moving from wikilog namespace to normal namespace.
			# Purge wikilog data.
			wfDebug( __METHOD__ . ": Moving from wikilog namespace to another " .
				"namespace ($oldns, $newns). Purging wikilog data.\n" );
			$dbw = wfGetDB( DB_MASTER );
			$dbw->delete( 'wikilog_wikilogs', array( 'wlw_page'   => $pageid ) );
			$dbw->delete( 'wikilog_posts',    array( 'wlp_page'   => $pageid ) );
			$dbw->delete( 'wikilog_posts',    array( 'wlp_parent' => $pageid ) );
			$dbw->delete( 'wikilog_authors',  array( 'wla_page'   => $pageid ) );
			$dbw->delete( 'wikilog_tags',     array( 'wlt_page'   => $pageid ) );
//			$dbw->delete( 'wikilog_comments', array( 'wlc_post'   => $pageid ) );
			# FIXME: Decide what to do with the comments.
		}
		return true;
	}

	/**
	 * LanguageGetSpecialPageAliases hook handler function.
	 * Adds language aliases for special pages.
	 * @note Deprecated in MediaWiki 1.16.
	 * @todo Remove this in Wikilog 1.1.0, along with support for Mw < 1.16.
	 */
	static function LanguageGetSpecialPageAliases( &$specialPageAliases, $lang ) {
		wfLoadExtensionMessages( 'Wikilog' );
		$title = Title::newFromText( wfMsg( 'wikilog-specialwikilog' ) );
		$specialPageAliases['SpecialWikilog'][] = $title->getDBKey();
		return true;
	}

	/**
	 * LanguageGetMagic hook handler function.
	 * Adds language aliases for magic words.
	 * @note Deprecated in MediaWiki 1.16.
	 * @todo Remove this in Wikilog 1.1.0, along with support for Mw < 1.16.
	 */
	static function LanguageGetMagic( &$words, $lang ) {
		require( 'Wikilog.i18n.magic.php' );
		if ( $lang == 'en' || !isset( $magicWords[$lang] ) ) {
			$words += $magicWords['en'];
		} else {
			$words += array_merge( $magicWords['en'], $magicWords[$lang] );
		}
		return true;
	}

	/**
	 * LoadExtensionSchemaUpdates hook handler function.
	 * Updates wikilog database tables.
	 *
	 * @todo Add support for PostgreSQL and SQLite databases.
	 */
	static function ExtensionSchemaUpdates() {
		global $wgDBtype, $wgExtNewFields, $wgExtPGNewFields, $wgExtNewIndexes, $wgExtNewTables;

		$dir = dirname( __FILE__ ) . '/';

		if ( $wgDBtype == 'mysql' ) {
			$wgExtNewTables[] = array( "wikilog_wikilogs", "{$dir}wikilog-tables.sql" );
		} else {
			// TODO: PostgreSQL, SQLite, etc...
			print "\n" .
				"Warning: There are no table structures for the Wikilog\n" .
				"extension other than for MySQL at this moment.\n\n";
		}
		return true;
	}

	/**
	 * UnknownAction hook handler function.
	 * Handles ?action=wikilog requests.
	 */
	static function UnknownAction( $action, $article ) {
		if ( $action == 'wikilog' && $article instanceof WikilogCustomAction ) {
			$article->wikilog();
			return false;
		}
		return true;
	}
}
