<?php

/**
 * This extension provides a parser function #createpageifnotex that can be used to create
 * additional auxiliary pages when a page is saved. New pages are only created if they do
 * not exist yet. The function takes two parameters: (1) the title of the new page, 
 * (2) the text to be used on the new page. It is possible to use &lt;nowiki&gt; tags in the
 * text to inserst wiki markup more conveniently.
 * 
 * The created page is attributed to the user who made the edit. The original idea for this
 * code was edveloped by Daniel Herzig at AIFB Karlsruhe. In his code, there were some further
 * facilities to show a message to the user about the pages that have been auto-created. This
 * is not implemented here yet (the basic way of doing this would be to insert some custom
 * HTML during 'OutputPageBeforeHTML').
 *
 * The code restricts the use of the parser function to MediaWiki content namespaces. So
 * templates, for example, cannot create new pages by accident. Also, the code prevents created
 * pages from creating further pages to avoid (unbounded) chains of page creations.
 *
 * @author Markus Kroetzsch
 * @author Daniel Herzig
 * @file
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'Not an entry point.' );
}

/**
 * This is decreased during page creation to avoid infinite recursive creation of pages.
 */
$egAutoCreatePageMaxRecursion = 1;

$egAutoCreatePageIgnoreEmptyTitle = false;

$egAutoCreatePageNamespaces = $wgContentNamespaces;

$GLOBALS['wgExtensionCredits']['other'][] = array(
	'name'         => 'AutoCreatePage',
	'version'      => '0.6',
	'author'       => '[http://korrekt.org Markus Krötzsch], Daniel Herzig', 
	'url'          => ' ',
	'description'  => 'Provides a parser function to create additional wiki pages with default content when saving a page.', //TODO i18n
	'license-name' => 'GPL-2.0+'
);

$GLOBALS['wgExtensionMessagesFiles']['AutoCreatePageMagic'] =  dirname(__FILE__) . '/AutoCreatePage.i18n.magic.php';



$GLOBALS['wgExtensionFunctions'][] = function() {

    wfDebugLog( 'autocreatepage', "i.0" );

	$GLOBALS['wgHooks']['ParserFirstCallInit'][] = function ( \Parser &$parser ) {

        wfDebugLog( 'autocreatepage', "a.0" );

        $parser->setFunctionHook( 'createPage', function( $parser ) {
            return createPageIfNotExisting( func_get_args() );
        } );

        $parser->setFunctionHook( 'append2Page', function( $parser ) {
              wfDebugLog( 'autocreatepage', "a.1" );
              return append2Page( func_get_args() );
        } );



        wfDebugLog( 'autocreatepage', "a.2" );
	};

	$GLOBALS['wgHooks']['ArticleEditUpdates'][] = 'doCreatePages';
    $GLOBALS['wgHooks']['ArticleEditUpdates'][] = 'doAppend2Pages';
};

/**
 * Handles the parser function for creating pages that don't exist yet,
 * filling them with the given default content. It is possible to use &lt;nowiki&gt;
 * in the default text parameter to insert verbatim wiki text.
 */
function createPageIfNotExisting( array $rawParams ) {

    global $egAutoCreatePageMaxRecursion, $egAutoCreatePageIgnoreEmptyTitle, $egAutoCreatePageNamespaces;

	if ( $egAutoCreatePageMaxRecursion <= 0 ) {
		return 'Error: Recursion level for auto-created pages exeeded.'; //TODO i18n
	}

	if ( isset( $rawParams[0] ) && isset( $rawParams[1] ) && isset( $rawParams[2] ) ) {
		$parser = $rawParams[0];
		$newPageTitleText = $rawParams[1];
		$newPageContent = $rawParams[2];
	} else {
		throw new MWException( 'Hook invoked with missing parameters.' );
	}

	if ( empty( $newPageTitleText ) ) {
		if ( $egAutoCreatePageIgnoreEmptyTitle === false ) {
			return 'Error: this function must be given a valid title text for the page to be created.'; //TODO i18n
		} else {
			return '';
		}
	}

	// Create pages only if the page calling the parser function is within defined namespaces
	if ( !in_array( $parser->getTitle()->getNamespace(), $egAutoCreatePageNamespaces ) ) {
		return '';
	}

	// Get the raw text of $newPageContent as it was before stripping <nowiki>:
	$newPageContent = $parser->mStripState->unstripNoWiki( $newPageContent );

	// Store data in the parser output for later use:
	$createPageData = $parser->getOutput()->getExtensionData( 'createPage' );
	if ( is_null( $createPageData ) ) {
		$createPageData = array();
	}
	$createPageData[$newPageTitleText] = $newPageContent;
	$parser->getOutput()->setExtensionData( 'createPage', $createPageData );

	return "";
}

/**
 * Creates pages that have been requested by the creat page parser function. This is done only
 * after the safe is complete to avoid any concurrent article modifications.
 * Note that article is, in spite of its name, a WikiPage object since MW 1.21.
 */
function doCreatePages( &$article, &$editInfo, $changed ) {
    wfDebugLog( 'autocreatepage', "d.0" );

    global $egAutoCreatePageMaxRecursion;

	$createPageData = $editInfo->output->getExtensionData( 'createPage' );
	if ( is_null( $createPageData ) ) {
		return true; // no pages to create
	}

	// Prevent pages to be created by pages that are created to avoid loops:
	$egAutoCreatePageMaxRecursion--;

	$sourceTitle = $article->getTitle();
	$sourceTitleText = $sourceTitle->getPrefixedText();

	foreach ( $createPageData as $pageTitleText => $pageContentText ) {
		$pageTitle = Title::newFromText( $pageTitleText );
		// wfDebugLog( 'createpage', "CREATE " . $pageTitle->getText() . " Text: " . $pageContent );

		if ( !is_null( $pageTitle ) && !$pageTitle->isKnown() && $pageTitle->canExist() ){
			$newWikiPage = new WikiPage( $pageTitle );
			$pageContent = ContentHandler::makeContent( $pageContentText, $sourceTitle );
			$newWikiPage->doEditContent( $pageContent,
				"Page created automatically by parser function on page [[$sourceTitleText]]" ); //TODO i18n

			// wfDebugLog( 'createpage', "CREATED PAGE " . $pageTitle->getText() . " Text: " . $pageContent );
		}
	}

	// Reset state. Probably not needed since parsing is usually done here anyway:
	$editInfo->output->setExtensionData( 'createPage', null ); 
	$egAutoCreatePageMaxRecursion++;

	return true;
}

/*
 *
 */

function append2Page(array $rawParams) {
    wfDebugLog( 'autocreatepage', "p.0" );

    global $egAutoCreatePageMaxRecursion, $egAutoCreatePageNamespaces;

    wfDebugLog( 'autocreatepage', "p.1" );

    if ( isset( $rawParams[0] ) && isset( $rawParams[1] ) && isset( $rawParams[2] ) ) {
        $parser = $rawParams[0];
        $targetPageTitleText = $rawParams[1];
        $targetPageContent2Append = $rawParams[2];
    } else {
        throw new MWException( 'Hook invoked with missing parameters.' );
    }

    wfDebugLog( 'autocreatepage', "p.2" );

    if ( empty( $targetPageTitleText ) ) {
        return 'Error: this function must be given a valid title text for the page to which contend will be appended.';
    }

    wfDebugLog( 'autocreatepage', "p.3" );

    // Append content to pages only if the page calling the parser function is within defined namespaces
    if ( !in_array( $parser->getTitle()->getNamespace(), $egAutoCreatePageNamespaces ) ) {
        return '';
    }

    wfDebugLog( 'autocreatepage', "p.4" );

    // Get the raw text of $targetPageContent2Append as it was before stripping <nowiki>:
    $targetPageContent2Append = $parser->mStripState->unstripNoWiki( $targetPageContent2Append );

    wfDebugLog( 'autocreatepage', "p.5" );

    // Store data in the parser output for later use:
    $append2PageData = $parser->getOutput()->getExtensionData( 'append2Page' );
    if ( is_null( $append2PageData ) ) {
        $append2PageData = array();
    }

    wfDebugLog( 'autocreatepage', "p.6" );

    $append2PageData[$targetPageTitleText] = $targetPageContent2Append;
    $parser->getOutput()->setExtensionData( 'append2Page', $append2PageData );

    wfDebugLog( 'autocreatepage', "p.7" );

    return "";
}

function doAppend2Pages(&$article, &$editInfo, $changed) {

    wfDebugLog( 'autocreatepage', "da.0" );

    global $egAutoCreatePageMaxRecursion;

    wfDebugLog( 'autocreatepage', "da.1" );

    $append2PageData = $editInfo->output->getExtensionData( 'append2Page' );
    if ( is_null( $append2PageData ) ) {
        return true; // no pages to which content will be appended
    }

    wfDebugLog( 'autocreatepage', "da.2" );

    // Prevent appending content to pages by pages to which content has been appended to avoid loops:
    $egAutoCreatePageMaxRecursion--;

    wfDebugLog( 'autocreatepage', "da.3" );

    $sourceTitle = $article->getTitle(); // title object
    $sourceTitleText = $sourceTitle->getPrefixedText(); // displayed text of title

    wfDebugLog( 'autocreatepage', "da.4" );

    foreach ( $append2PageData as $pageTitleText => $pageContentText2Append ) {
        wfDebugLog( 'autocreatepage', "da.4.1" );

        $pageTitle = Title::newFromText( $pageTitleText );

        wfDebugLog( 'autocreatepage', "da.4.2" );

        if ( !is_null( $pageTitle ) && $pageTitle->isKnown() ){
            wfDebugLog( 'autocreatepage', "da.4.2.1" );

            $targetWikiPage = new WikiPage( $pageTitle );
            wfDebugLog( 'autocreatepage', "da.4.2.2" );
            //wfDebugLog( 'autocreatepage', var_export($targetWikiPage, true));



            $currentText = $targetWikiPage->getContent()->getText();
            wfDebugLog( 'autocreatepage', "da.4.2.3" );
            //wfDebugLog( 'autocreatepage', "Content null? " . (is_null($currentText) ? "true" : "false") );
            //wfDebugLog( 'autocreatepage', "Content: " . var_export($currentText, true) );
            //wfDebugLog( 'autocreatepage', "Content2Append: " . var_export($pageContentText2Append, true) );

            $newContent = ContentHandler::makeContent( $currentText . $pageContentText2Append, $sourceTitle );

            wfDebugLog( 'autocreatepage', "da.4.2.4" );

            // $targetWikiPage->doEdit( $currentText . $pageContentText2Append,
            $targetWikiPage->doEditContent( $newContent,
                "Content appended to Page automatically by parser function on page [[$sourceTitleText]]" );
            wfDebugLog( 'autocreatepage', "da.4.2.5" );
        }

        wfDebugLog( 'autocreatepage', "da.4.3" );
    }

    wfDebugLog( 'autocreatepage', "da.5" );

    // Reset state. Probably not needed since parsing is usually done here anyway:
    $editInfo->output->setExtensionData( 'append2Page', null );
    $egAutoCreatePageMaxRecursion++;

    wfDebugLog( 'autocreatepage', "da.6" );

    return true;
}

