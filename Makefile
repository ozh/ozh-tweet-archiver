default: _displayHelpMessage

clear: _cleanUpTargetDir
help: _displayHelpMessage
zip: _createPluginZipFile

_cleanUpTargetDir:
	rm -f target/*

_displayHelpMessage:
	@echo "Commands available:"
	@echo "\t- clear: Clean up target dir (used to generate zip file)"
	@echo "\t- help: Display this message"
	@echo "\t- zip: Pack the plugin into a zip file to distribute"

_createPluginZipFile: _cleanUpTargetDir
	zip -r -9 target/ozh-tweet-archiver.zip ozh-tweet-archiver
