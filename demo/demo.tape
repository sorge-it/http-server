# The demo of the README: the page from `php -S` in a second process shows no user, the page from
# HttpServer in the test process shows Ada. `make -C demo gif`; https://github.com/charmbracelet/vhs

Output ../output/demo.gif

Set Shell bash
Set Width 1200
Set Height 280
Set FontSize 22
Set Theme "Catppuccin Mocha"
Set Padding 24
Set Framerate 24
Set TypingSpeed 25ms
Set CursorBlink false
# Each command waits for the prompt, so VHS does not type the next command while a slow server still
# starts.
Set WaitPattern /^\$\s*$/
Set WaitTimeout 60s

# The first frame already shows what the test holds.
Hide
Type "export PS1='$ ' && rm -f app.sqlite* && clear && echo -e '\e[2m# The test holds the user Ada in a transaction it never commits.\n# Chromium loads the users page from each server.\e[0m'"
Enter
Wait
Show

Sleep 1.5s
Type "php php-s.php        # php -S: a second process"
Enter
Wait
Sleep 1.5s
Type "php http-server.php  # HttpServer: the test process"
Enter
Wait
Sleep 5s
