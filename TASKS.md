1. Vitest test setup for js
2. Actually enforce some validation for prompts that handle filetypes. E.g. an AltText action should never be called with a video or audio file or PDF
   1. We should add this to the MagicAction class
3. Think about nice Actions for extractions from PDFs and documents
   1. I think we could do some inline image creation using nano banana or something like that
4. Add pest browser testing to ensure, the right actions are being displayed based on field config
5. Enable selecting multiple magic actions in one field
6. Think about how we can introduce batch processing for magic actions
7. Make icons configurable in MagicAction class
