# The social preview of the GitHub repository: the name and the page from each server, 1280 × 640.
# Run through `make -C demo png`; https://github.com/charmbracelet/vhs

Set Shell bash
Set Width 1280
Set Height 640
Set FontSize 32
Set Theme "Catppuccin Mocha"
Set Padding 28
Set CursorBlink false

Hide
Type "export PS1='$ ' && php social.php && sleep 120"
Enter
# The servers take a moment to start; wait for the last line, not for a fixed time.
Wait+Screen@60s /Tested against FrankenPHP/
Show
Sleep 500ms

Screenshot ../output/social.png
# The wait gives VHS time to write the file.
Sleep 1s
