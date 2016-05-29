<?php
/**************************************************************************
* This file is part of the WebIssues Server program
* Copyright (C) 2006 Michał Męciński
* Copyright (C) 2007-2015 WebIssues Team
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
**************************************************************************/

if ( !defined( 'WI_VERSION' ) ) die( -1 );

class Mobile_Client_IssuesList extends System_Web_Component
{
    protected function __construct()
    {
        parent::__construct();
    }

    protected function execute()
    {
        $issueManager = new System_Api_IssueManager();
        $projectManager = new System_Api_ProjectManager();
        $typeManager = new System_Api_TypeManager();

        $issueId = (int)$this->request->getQueryString( 'issue' );
        $typeId = (int)$this->request->getQueryString( 'type' );
        if ( $typeId ) {
            $type = $typeManager->getIssueType( $typeId );
            $folder = null;
            $this->folderName = $type[ 'type_name' ];
            $this->isType = true;
            $key = 'type';
            $id = $typeId;
        } else {
            if ( $issueId ) {
                $issue = $issueManager->getIssue( $issueId );
                $folder = $projectManager->getFolderFromIssue( $issue );
                $folderId = $folder[ 'folder_id' ];
            } else {
                $folderId = (int)$this->request->getQueryString( 'folder' );
                $folder = $projectManager->getFolder( $folderId );
            }
            $type = $typeManager->getIssueTypeForFolder( $folder );
            $this->folderName = $folder[ 'folder_name' ];
            $key = 'folder';
            $id = $folderId;
        }

        if ( !$issueId )
            $this->view->setSlot( 'page_title', $this->folderName );

        $viewManager = new System_Api_ViewManager();
        $views = $viewManager->getViewsForIssueType( $type );

        $initialView = $viewManager->getViewSetting( $type, 'initial_view' );

        if ( $initialView != '' && empty( $views[ 1 ][ (int)$initialView ] ) )
            $initialView = '';

        $viewParam = $this->request->getQueryString( 'view' );

        if ( $viewParam == '' && $initialView != '' )
            $viewParam = (int)$initialView;
        else
            $viewParam = (int)$viewParam;

        $this->viewForm = new System_Web_Form( 'switchView', $this );
        $this->viewForm->addField( 'viewSelect', $viewParam );

        $this->viewOptions[ '' ] = $this->tr( 'All Issues' );
        if ( !empty( $views[ 0 ] ) )
            $this->viewOptions[ $this->tr( 'Personal Views' ) ] = $views[ 0 ];
        if ( !empty( $views[ 1 ] ) )
            $this->viewOptions[ $this->tr( 'Public Views' ) ] = $views[ 1 ];

        $this->viewForm->addItemsRule( 'viewSelect', $this->viewOptions );

        if ( $this->viewForm->loadForm() ) {
            $this->viewForm->validate();

            if ( !$this->viewForm->hasErrors() ) {
                if ( $this->viewSelect == $initialView )
                    $url = $this->filterQueryString( '/mobile/client/index.php', array( 'ps', 'po', 'ppg' ), array( $key => $id ) );
                else if ( $this->viewSelect != '' )
                    $url = $this->filterQueryString( '/mobile/client/index.php', array( 'ps', 'po', 'ppg' ), array( $key => $id, 'view' => $this->viewSelect ) );
                else
                    $url = $this->filterQueryString( '/mobile/client/index.php', array( 'ps', 'po', 'ppg' ), array( $key => $id, 'view' => 0 ) );
                $this->response->redirect( $url );
            }
        }

        $query = $this->request->getQueryString( 'q' );
        $queryColumn = (int)$this->request->getQueryString( 'qc' );

        $this->searchForm = new System_Web_Form( 'search', $this );
        $this->searchForm->addField( 'searchBox', $query );
        $this->searchForm->addField( 'searchOption', $queryColumn );

        if ( $this->searchForm->loadForm() ) {
            $url = $this->filterQueryString( '/mobile/client/index.php', array( 'ps', 'po', 'ppg', 'sort', 'order', 'view' ), array( $key => $id, 'q' => $this->searchBox, 'qc' => $this->searchOption ) );
            $this->response->redirect( $url );
        }

        $javaScript = new System_Web_JavaScript( $this->view );
        $javaScript->registerAutoSubmit( $this->viewForm->getFormSelector(), $this->viewForm->getFieldSelector( 'viewSelect' ),
            $this->viewForm->getSubmitSelector( 'go' ) );

        $queryGenerator = new System_Api_QueryGenerator();
        if ( $folder )
            $queryGenerator->setFolder( $folder );
        else
            $queryGenerator->setIssueType( $type );

        $personalViewId = 0;

        if ( $viewParam != 0 ) {
            $view = $viewManager->getViewForIssueType( $type, $this->viewSelect );
            $definition = $view[ 'view_def' ];
            if ( !$view[ 'is_public' ] )
                $personalViewId = $view[ 'view_id' ];
        } else {
            $definition = $viewManager->getViewSetting( $type, 'default_view' );
        }
        if ( $definition != null )
            $queryGenerator->setViewDefinition( $definition );

        if ( $query != '' )
            $queryGenerator->setSearchText( $queryColumn, $query );

        $helper = new System_Web_ColumnHelper();
        $this->headers = $helper->getColumnHeaders() + $queryGenerator->getUserColumnHeaders();

        foreach ( $queryGenerator->getSearchableColumns() as $column )
            $searchOptions[ $column ] = $this->headers[ $column ];

        $javaScript->registerSearchOptions( $this->searchForm->getFieldSelector( 'searchBox' ), $this->searchForm->getFieldSelector( 'searchOption' ), $searchOptions );

        $serverManager = new System_Api_ServerManager();
        $pageSize = $serverManager->getSetting( 'folder_page_mobile' );

        $this->grid = new System_Web_Grid();
        $this->grid->setPageSize( $pageSize );
        $this->grid->setSelection( $issueId );

        $this->grid->setFilterParameters( array( 'ps', 'po', 'ppg', 'sort', 'order', 'page', 'view', 'q', 'qc' ) );
        $this->grid->setMergeParameters( array( $key => $id ) );

        $this->grid->setColumns( $queryGenerator->getGridColumns() );
        $this->grid->setDefaultSort( $queryGenerator->getSortColumn(), $queryGenerator->getSortOrder() );

        $connection = System_Core_Application::getInstance()->getConnection();

        $query = $queryGenerator->generateCountQuery();
        $this->grid->setRowsCount( $connection->queryScalarArgs( $query, $queryGenerator->getQueryArguments() ) );

        $query = $queryGenerator->generateSelectQuery();
        $page = $connection->queryPageArgs( $query, $this->grid->getOrderBy(), $this->grid->getPageSize(),
            $this->grid->getOffset(), $queryGenerator->getQueryArguments() );

        $this->emailEngine = $serverManager->getSetting( 'email_engine' ) != '';

        $formatter = new System_Api_Formatter();

        $this->issues = array();
        foreach ( $page as $row ) {
            $issue = array();
            $issue[ 'stamp_id' ] = $row[ 'stamp_id' ];
            $issue[ 'read_id' ] = $row[ 'read_id' ];
            if ( $this->emailEngine )
                $issue[ 'subscription_id' ] = $row[ 'subscription_id' ];
            $issue[ 'tip_name' ] = $row[ 'issue_name' ];
            $issue[ 'issue_name' ] = $this->truncate( $row[ 'issue_name' ], 60 );
            $this->issues[ $row[ 'issue_id' ] ] = $issue;
        }

        $principal = System_Api_Principal::getCurrent();

        $this->toolBar = new System_Web_ToolBar();
        $this->toolBar->setFilterParameters( array( 'ps', 'po', 'ppg', 'sort', 'order', 'page', 'view', 'q', 'qc' ) );

        if ( $principal->isAuthenticated() ) {
            $this->toolBar->addFixedCommand( '/mobile/client/issues/addissue.php', '/common/images/issue-new-16.png', $this->tr( 'Add Issue' ), array( $key => $id ) );
            $this->toolBar->addFixedCommand( '/mobile/client/issues/markall.php', '/common/images/folder-read-16.png', $this->tr( 'Mark All As Read' ), array( $key => $id, 'status' => 1 ) );
            $this->toolBar->addFixedCommand( '/mobile/client/issues/markall.php', '/common/images/folder-unread-16.png', $this->tr( 'Mark All As Unread' ), array( $key => $id, 'status' => 0 ) );
        }
    }
}
