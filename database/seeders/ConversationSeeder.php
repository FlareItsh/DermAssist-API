<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Seeder;

class ConversationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $patient = User::where('email', 'patient@dermassist.com')->first();
        $doctor = User::where('email', 'doctor@dermassist.com')->first();

        if ($patient && $doctor) {
            $conversation = Conversation::firstOrCreate([
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
            ]);

            $messages = [
                [
                    'sender_id' => $patient->id,
                    'message' => 'Hello Doctor! I have some concerns about a rash on my arm.',
                    'created_at' => now()->subMinutes(10),
                ],
                [
                    'sender_id' => $doctor->id,
                    'message' => 'Hello John. I understand. Could you please describe the rash and if it is itchy or painful?',
                    'created_at' => now()->subMinutes(8),
                ],
                [
                    'sender_id' => $patient->id,
                    'message' => 'It is a bit itchy and red. It appeared yesterday morning.',
                    'created_at' => now()->subMinutes(5),
                ],
                [
                    'sender_id' => $doctor->id,
                    'message' => 'I see. Please upload a clear photo of the affected area so I can take a better look.',
                    'created_at' => now()->subMinutes(2),
                ],
            ];

            foreach ($messages as $msgData) {
                Message::firstOrCreate([
                    'conversation_id' => $conversation->id,
                    'sender_id' => $msgData['sender_id'],
                    'message' => $msgData['message'],
                ], [
                    'created_at' => $msgData['created_at'],
                    'updated_at' => $msgData['created_at'],
                ]);
            }
        }
    }
}
