1. Actually enforce some validation for prompts that handle filetypes. E.g. an AltText action should never be called with a video or audio file or PDF
   1. We should add this to the MagicAction class
2. Think about nice Actions for extractions from PDFs and documents
   1. I think we could do some inline image creation using nano banana or something like that
3. Fix browser tests
4. Enable selecting multiple magic actions in one field
5. Think about how we can introduce batch processing for magic actions
6. Probably provide a closure, to be defined on the MagicAction class, to be able to pass in the context of the action, e.g. available tags from a taxonomy etc.
7. Let's add an ImageCaption action, that generates a caption for an image
