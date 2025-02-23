/// <reference types="vite/client" />

declare global {
  interface Window {
    StatamicConfig: {
      magicFields: Array<{
        type: string
        action: string
        prompt: string
      }>
      providers: {
        openai?: {
          api_key: string
        }
        google?: {
          api_key: string
        }
      }
    }
    Statamic: {
      $fieldActions: {
        add: (type: string, config: any) => void
      }
      $toast: {
        error: (message: string) => void
      }
      Store: {
        store: {
          state: {
            publish: Record<string, any>
          }
        }
      }
    }
  }
}

import { Dotprompt } from 'dotprompt'
import { toGeminiRequest } from '../../node_modules/dotprompt/src/adapters/gemini.js'
import { toOpenAIRequest } from '../../node_modules/dotprompt/src/adapters/openai.js'

class MagicActionsService {
  private endpoints = {
    openai: 'https://api.openai.com/v1/chat/completions',
    google: 'https://generativelanguage.googleapis.com/v1beta/models',
  }

  constructor() {}

  async executePrompt(rendered: any, provider: string, model: string) {
    if (provider === 'openai') {
      const apiKey = window.StatamicConfig.providers?.openai?.api_key

      if (!apiKey) {
        throw new Error('OpenAI API key is not configured')
      }

      const openaiFormat = toOpenAIRequest(rendered)

      const response = await fetch(this.endpoints.openai, {
        method: 'POST',
        body: JSON.stringify(openaiFormat),
        headers: {
          'content-type': 'application/json',
          authorization: 'Bearer ' + apiKey,
        },
      })
      return await response.json()
    }

    if (provider === 'google') {
      const apiKey = window.StatamicConfig.providers?.google?.api_key

      if (!apiKey) {
        throw new Error('Google API key is not configured')
      }

      const geminiFormat = toGeminiRequest(rendered)
      const response = await fetch(`${this.endpoints.google}/${model}:generateContent?key=${apiKey}`, {
        method: 'POST',
        body: JSON.stringify(geminiFormat.request),
        headers: { 'content-type': 'application/json' },
      })
      return await response.json()
    }

    throw new Error(`Unsupported provider: ${provider}`)
  }

  async getCompletion(response: any, provider: string): Promise<{ data: Array<string> | string }> {
    if (provider === 'openai') {
      const content = response.choices[0].message.content
      const match = content.match(/(\[.*\]|\{.*\})/s)?.[0] || content
      return JSON.parse(match)
    }

    if (provider === 'google') {
      const text = response.candidates[0].content.parts[0].text
      const match = text.match(/(\[.*\]|\{.*\})/s)?.[0] || text
      return JSON.parse(match)
    }

    throw new Error(`Unsupported provider: ${provider}`)
  }

  async generateFromPrompt(text: string, promptMarkdown: string) {
    const dotprompt = new Dotprompt()

    const parsed = dotprompt.parse(promptMarkdown)
    const [provider, modelName] = parsed.model?.split('/') ?? ['openai', 'gpt-3.5-turbo']

    parsed.model = modelName

    const rendered = await dotprompt.render(parsed as unknown as string, { input: { text } })
    const response = await this.executePrompt(rendered, provider, modelName)
    return await this.getCompletion(response, provider)
  }
}

const service = new MagicActionsService()

// Utility functions
const extractText = (content: any): string => {
  if (!content || typeof content !== 'object') return ''

  if (content.type === 'text' && content.text) {
    return content.text
  }

  if (Array.isArray(content)) {
    return content.map(extractText).filter(Boolean).join('\n')
  }

  return Object.values(content).map(extractText).filter(Boolean).join('\n')
}

const findFieldByHandle = (tabs: Array<any>, targetHandle: string) => {
  for (const tab of tabs) {
    for (const section of tab.sections || []) {
      for (const field of section.fields || []) {
        if (field.handle === targetHandle) {
          return field
        }
      }
    }
  }
  return null
}

// Register field actions for each fieldtype with magic tags enabled
const magicActionsService = new MagicActionsService()

const registerFieldActions = async () => {
  try {
    const map = {
      terms: 'relationship-fieldtype',
    }

    window.StatamicConfig.magicFields.forEach((field: any) => {
      const componentName = `${field.component}-fieldtype`

      if (componentName) {
        window.Statamic.$fieldActions.add(componentName, {
          title: field.title,
          quick: true,
          icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" /></svg>`,
          run: async ({ handle, value, update, store, storeName }) => {
            try {
              const state = store.state.publish[storeName]
              const fieldConfig = findFieldByHandle(state.blueprint.tabs, handle)

              if (!fieldConfig?.magic_tags_source) {
                throw new Error('No source field configured for magic tags')
              }

              const sourceText = extractText(state.values[fieldConfig.magic_tags_source])
              if (!sourceText) {
                throw new Error('Source field is empty')
              }

              const { data } = await magicActionsService.generateFromPrompt(sourceText, field.prompt)
              update(Array.isArray(data) ? data.slice(0, 10) : data)
            } catch (error) {
              console.error('Error in Magic Tags action:', error)
              window.Statamic.$toast.error(error.message || 'Failed to generate tags')
            }
          },
        })
      }
    })
  } catch (error) {
    console.error('Error registering field actions:', error)
  }
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', registerFieldActions)
} else {
  registerFieldActions()
}
