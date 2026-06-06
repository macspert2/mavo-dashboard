This is a project for a wordpress plugin which genrates a dashboard in the admin backen.
The plugin is completely invisible on the frontend (no html, js or css is being loaded there).
Access to the dashboard is via a new menu item in the main left hand menu.
Here's what the initial version of the dashboard should show:
The output should be split into 3 horizontal full width parts
1. slim top part:
- a list of the most used tags in order of occurence - top 50, comma separated in one wrapping line with number of occurence behind the tag in brackets.
- the tags are clickable and a click will reload the content of the second part to show only data relevant to that tag
2. half of the remaining height:
- a scrollable vertical list of all posts with the tag selected in the top part
- columns (wrapping if too wide): post title, post slug, publish date, featured image thumbnail, number of words, number of comments, number of images, number of internal links, number of booking links, number of discovercars links, values of bpul and maj fields - all ordered DESC by the last column: number of views from postmeta
- the titles are clickable and a click will reload the content of the third part to show detailed information about the selected post
3. remaining height:
- all the relevant detail about the selected post in a scrollable vertical list
- columns (wrapping if too wide): internal links, booking and discovercars links, other external links, image thumbnails
The query selecting the posts should repect the language choice for the backen from polyland (all, fr, en, de) 

Be proactive and inventive

