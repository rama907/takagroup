// index.js
// Pastikan Anda telah menginstal npm install discord.js axios dotenv

const { Client, GatewayIntentBits } = require('discord.js');
const axios = require('axios');
require('dotenv').config();

// --- Konfigurasi ---
const API_KEY = process.env.API_SECRET_KEY;
const API_URL = process.env.API_ENDPOINT_URL;

const client = new Client({
    intents: [
        GatewayIntentBits.Guilds,
        GatewayIntentBits.GuildMessages,
        GatewayIntentBits.MessageContent
    ]
});

client.once('ready', async () => {
    console.log(`Bot Siap! Logged in as ${client.user.tag}`);

    // --- Daftarkan Slash Commands ---
    const commands = [
        {
            name: 'onduty',
            description: 'Melakukan Clock In ke sistem manajemen Elysium.'
        },
        {
            name: 'offduty',
            description: 'Melakukan Clock Out dari sistem manajemen Elysium.'
        }
    ];
    // Daftarkan ke server Anda (Ganti YOUR_GUILD_ID dengan ID Server Discord Anda)
    // Jika tidak ada ID server, Bot akan mendaftar secara global (membutuhkan waktu 1 jam)
    // await client.application.commands.set(commands, 'YOUR_GUILD_ID'); 
    await client.application.commands.set(commands); 

    console.log('Slash commands registered.');
});

// --- Logic API Call ---
async function sendDutyAction(interaction, action) {
    // ID Discord pengguna yang menjalankan perintah
    const discordId = interaction.user.id;
    const employeeName = interaction.user.username; 

    // Payload yang dikirim ke API PHP Anda
    const payload = {
        api_key: API_KEY,
        discord_id: discordId,
        action: action 
    };

    try {
        await interaction.deferReply({ ephemeral: true }); // Tampilkan status "Bot sedang berpikir"

        const response = await axios.post(API_URL, payload);
        const data = response.data;

        let replyMessage = ``;

        if (data.status === 'success') {
            replyMessage = `✅ **${action.toUpperCase()} BERHASIL!**\nSistem mencatat: ${data.message}`;
        } else if (data.status === 'warning') {
            replyMessage = `⚠️ **PERINGATAN:** ${data.message}`;
        } else {
             // Termasuk Unauthorized atau Employee not found
            replyMessage = `❌ **GAGAL EKSEKUSI:** ${data.message}\nPastikan Discord ID Anda benar dan sudah terdaftar di [Link Akun Discord] di website.`;
        }

        await interaction.editReply(replyMessage);

    } catch (error) {
        console.error('API Call Error:', error.message);
        interaction.editReply(`❌ Terjadi Kesalahan Server API. Cek log Bot.`);
    }
}


// --- Logic Command Handler ---
client.on('interactionCreate', async interaction => {
    if (!interaction.isCommand()) return;

    if (interaction.commandName === 'onduty') {
        await sendDutyAction(interaction, 'clock_in');
    } else if (interaction.commandName === 'offduty') {
        await sendDutyAction(interaction, 'clock_out');
    }
});


// Login Bot
client.login(process.env.DISCORD_BOT_TOKEN);