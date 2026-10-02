<?php

namespace App\Livewire;

use App\Services\InventoryAgentService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;
use RuntimeException;
use Throwable;

/** Provides a guarded Livewire chat interface to the inventory tools. */
class AiChatAssistant extends Component
{
    // El cliente no puede editar mensajes previos ni falsificar resultados de tools.
    #[Locked]
    public array $messages = [];

    // Livewire sincroniza esta propiedad con el cuadro de texto del chat.
    #[Validate('required|string|max:2000')]
    public string $userMessage = '';

    /** Valida el mensaje, lo añade al historial y coordina una respuesta del modelo. */
    public function sendMessage(InventoryAgentService $agentService): void
    {
        // Limpia espacios externos antes de validar la longitud y el contenido.
        $this->userMessage = trim($this->userMessage);
        $validated = $this->validate();

        // Guarda la pregunta antes de cualquier petición para conservar el contexto.
        $this->messages[] = [
            'role' => 'user',
            'content' => $validated['userMessage'],
        ];
        $this->userMessage = ''; // Vacía el formulario mientras se procesa la petición.

        // Lee la clave desde configuración para no exponerla en JavaScript ni Blade.
        $apiKey = config('services.groq.key');

        // Informa cómo activar el servicio sin intentar una petición sin credenciales.
        if (! is_string($apiKey) || trim($apiKey) === '') {
            $this->appendAssistantMessage('El asistente no está configurado. Agrega GROQ_API_KEY al archivo .env para habilitarlo.');

            return;
        }

        // Oculta detalles técnicos al usuario y conserva el error en el log del servidor.
        try {
            $this->completeConversation($agentService, $apiKey);
        } catch (Throwable $exception) {
            report($exception);
            $this->appendAssistantMessage('No pude comunicarme con el servicio de inteligencia artificial. Intenta de nuevo en un momento.');
        }
    }

    public function render(): View
    {
        // Livewire renderiza la vista independiente del chat.
        return view('livewire.ai-chat-assistant');
    }

    /** Runs tool rounds until the model returns a user-facing answer. */
    private function completeConversation(InventoryAgentService $agentService, string $apiKey): void
    {
        // Limita las rondas para prevenir ciclos interminables de herramientas.
        for ($round = 0; $round < 3; $round++) {
            // Envía el historial actual y recibe una respuesta o solicitudes de tools.
            $completion = $this->requestCompletion($agentService, $apiKey);
            $assistantMessage = $completion['choices'][0]['message'] ?? null;

            if (! is_array($assistantMessage)) {
                // Una respuesta sin el objeto message no cumple el protocolo esperado.
                throw new RuntimeException('The AI provider returned an invalid chat completion.');
            }

            // Una lista vacía de tool_calls significa que ya llegó la respuesta final.
            $toolCalls = $assistantMessage['tool_calls'] ?? [];

            if (! is_array($toolCalls) || $toolCalls === []) {
                $content = $assistantMessage['content'] ?? null;
                // Añade texto final legible y sustituye respuestas vacías por un fallback.
                $this->appendAssistantMessage(is_string($content) && trim($content) !== ''
                    ? trim($content)
                    : 'No encontré una respuesta para esa solicitud.');

                return;
            }

            // Conserva solamente los campos que exige el siguiente request compatible.
            $normalizedToolCalls = [];

            foreach ($toolCalls as $toolCall) {
                // Rechaza invocaciones malformadas antes de ejecutar cualquier cambio.
                if (! is_array($toolCall)
                    || ! is_string($toolCall['id'] ?? null)
                    || ! is_array($toolCall['function'] ?? null)
                    || ! is_string($toolCall['function']['name'] ?? null)) {
                    throw new RuntimeException('The AI provider returned an invalid tool call.');
                }

                // Normaliza argumentos y metadatos sin confiar en tipos inesperados.
                $normalizedToolCalls[] = [
                    'id' => $toolCall['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $toolCall['function']['name'],
                        'arguments' => is_string($toolCall['function']['arguments'] ?? null)
                            ? $toolCall['function']['arguments']
                            : '{}',
                    ],
                ];
            }

            // Guarda la respuesta assistant con tool_calls antes de adjuntar sus resultados.
            $this->messages[] = [
                'role' => 'assistant',
                'content' => is_string($assistantMessage['content'] ?? null) ? $assistantMessage['content'] : null,
                'tool_calls' => $normalizedToolCalls,
            ];

            foreach ($normalizedToolCalls as $toolCall) {
                // Decodifica el JSON enviado por el proveedor como un arreglo PHP.
                $toolArguments = json_decode($toolCall['function']['arguments'], true);
                // Ejecuta la función permitida o prepara un error para argumentos inválidos.
                $toolResult = is_array($toolArguments)
                    ? $agentService->executeTool($toolCall['function']['name'], $toolArguments)
                    : ['success' => false, 'message' => 'Los argumentos de la herramienta no son válidos.'];

                // Notifica al dashboard solo si la tool de escritura terminó correctamente.
                if (in_array($toolCall['function']['name'], ['adjust_stock', 'assign_product_to_department'], true)
                    && ($toolResult['success'] ?? false) === true) {
                    $this->dispatch('inventory-updated');
                }

                // El rol tool enlaza su JSON de resultado con el tool_call_id original.
                $this->messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'content' => json_encode($toolResult, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        // Informa el límite cuando el proveedor solicita más rondas de las permitidas.
        $this->appendAssistantMessage('La solicitud requirió demasiadas operaciones. Puedes dividirla en pasos más pequeños.');
    }

    /** Sends the conversation and tool definitions to Groq's OpenAI-compatible endpoint. */
    private function requestCompletion(InventoryAgentService $agentService, string $apiKey): array
    {
        // El mensaje de sistema explica al modelo cuándo y cómo elegir cada herramienta.
        $messages = array_merge([
            [
                'role' => 'system',
                'content' => 'Eres el asistente de Inventario Central. Responde siempre en español. Si el usuario pide una tabla, responde con una tabla Markdown; la interfaz la presenta de forma segura. Usa check_stock para consultas. Para asignar productos a departamentos, usa assign_product_to_department indicando el nombre del departamento, el nombre o clave del producto y la cantidad solicitada; esta herramienta crea el departamento si hace falta y descuenta las unidades del inventario. Nunca uses adjust_stock para simular una asignación. En los resultados, available_stock son unidades en bodega, departments[].quantity son unidades asignadas y total_stock es disponible más asignado. Si preguntan cuántas unidades existen, informa el total y separa disponibles de asignadas. Ejecuta ajustes solo si el usuario lo solicita explícitamente. No afirmes que una operación se realizó si la herramienta devuelve success=false.',
            ],
        ], $this->messages);

        // Envía historial y esquemas a Groq usando el formato OpenAI-compatible.
        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(45)
            ->post(config('services.groq.chat_completions_url'), [
                // Configura el modelo sin fijar ningún secreto en el código.
                'model' => config('services.groq.model', 'openai/gpt-oss-120b'),
                'messages' => $messages,
                'tools' => $agentService->getToolsSchema(),
                'tool_choice' => 'auto',
            ]);

        // Convierte respuestas HTTP fallidas en excepciones controladas por sendMessage.
        $response->throw();
        // Decodifica la respuesta JSON para inspeccionar message y tool_calls.
        $completion = $response->json();

        // Evita procesar cuerpos vacíos o JSON que no sea un objeto.
        if (! is_array($completion)) {
            throw new RuntimeException('The AI provider returned an invalid response.');
        }

        return $completion;
    }

    private function appendAssistantMessage(string $content): void
    {
        // Añade errores controlados o respuestas finales al historial visible.
        $this->messages[] = [
            'role' => 'assistant',
            'content' => $content,
        ];
    }
}
