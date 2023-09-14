<?php
namespace Helper;

// here you can define custom actions
// all public methods declared in helper class will be available in $I

class Utils extends \Codeception\Module
{

    public function importJson( $json_file ) {

        // Import the json file Save and see the selection after form submit
        $this->getModule('WPBrowser')->loginAsAdmin();

        $this->getModule('WPBrowser')->amOnPage('/wp-admin/edit.php?post_type=acf-field-group&page=acf-tools');

        $this->getModule('WPBrowser')->attachFile('//input[@id="acf_import_file"]', $json_file );

        $this->getModule('WPBrowser')->submitForm('//*[@id="acf-admin-tool-import"]//*[@type="submit"]', [], 'Choose File');
    }

	public function deleteAllFieldGroups() {
		$this->getModule('WPBrowser')->loginAsAdmin();
		$this->getModule('WPBrowser')->amOnPage('/wp-admin/edit.php?post_type=acf-field-group');
		$this->getModule('WPBrowser')->see( 'tr.type-acf-field-group' );
		$this->getModule('WPBrowser')->checkOption( 'form input[name="post[]"]' );
		$this->getModule('WPBrowser')->selectOption( '#bulk-action-selector-bottom', 'trash' );
		$this->getModule( 'WPBrowser' )->submitForm( '#posts-filter', [], 'Apply' );
		$this->getModule('WPBrowser')->dontSee( 'tr.type-acf-field-group' );
	}

	/**
	 * @return bool
	 * @throws \Codeception\Exception\ModuleException
	 */
	public function haveAcfProActive(): bool {
		$this->getModule('WPBrowser' )->loginAsAdmin();
		$this->getModule('WPBrowser' )->amOnPluginsPage();
		$active_plugins = $this->getModule('WPDb' )->grabOptionFromDatabase( 'active_plugins' );

		return in_array( 'advanced-custom-fields-pro/acf.php', $active_plugins, true  );

	}
}
