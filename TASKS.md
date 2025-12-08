1. Make js managable by refactoring the addon.ts file into modules.
   1. fix logging
   2. Add unit tests to make sure we process the differnt input and output data types correctly in JS
2. Vites test setup for js
3. Actually enforce some validation for prompts that handle filetypes. E.g. an AltText action should never be called with a video or audio file or PDF
   1. We should add this to the MagicAction class
4. Think about nice Actions for extractions from PDFs and documents
5. Add pest browser testing to ensure, the right actions are being displayed based on field config
6. Enable selecting multiple magic actions in one field
7. Ignore field-config for magic actions, that are not be enabled in addon config
8. Decouple background processing from frontend actions. Background processing must complete even if navigating away etc.
9. Think about how we can introduce batch processing for magic actions
