// index.js - DISCORD BOT
const { Client, GatewayIntentBits, ActivityType } = require('discord.js');
const axios = require('axios');
require('dotenv').config();

// --- Konfigurasi Azure Environment Variables ---
const API_SECRET_KEY = process.env.API_SECRET_KEY;
const API_ENDPOINT_URL = process.env.API_ENDPOINT_URL; // URL ke duty_action.php

// NEW: URL ke endpoint status (sesuaikan path-nya)
const STATUS_API_URL = process.env.STATUS_API_URL || API_ENDPOINT_URL.replace('duty_action.php', 'get_duty_status.php');

const client = new Client({
    intents: [
        GatewayIntentBits.Guilds,
        GatewayIntentBits.GuildMessages,
        GatewayIntentBits.MessageContent
    ]
});

// --- FUNGSI KRITIS: Mengambil status dan mengupdate Bot Presence ---
async function updateBotActivity() {
    try {
        const response = await axios.get(STATUS_API_URL); // Panggil API status baru
        const data = response.data;

        if (data.status === 'success') {
            const onDuty = data.on_duty_count;
            const totalActive = data.total_active_count;
            
            // Format status yang diinginkan: "Watching [10/29] On Elysium Night Club"
            const activityMessage = `[${onDuty}/${totalActive}] On Elysium Night Club`;
            
            client.user.setActivity(activityMessage, { type: ActivityType.Watching });
            console.log(`[PRESENCE] Status updated to: ${activityMessage}`);
        } else {
            console.error('[PRESENCE] API Status Error:', data.message);
            client.user.setActivity('API Error', { type: ActivityType.Watching });
        }
    } catch (error) {
        console.error('[PRESENCE] Gagal memanggil API Status:', error.message);
        client.user.setActivity('Offline', { type: ActivityType.Watching });
    }
}


// --- Logic Utama Bot ---
client.once('ready', async () => {
    console.log(`Bot Siap! Logged in as ${client.user.tag}`);
    
    // 1. Daftarkan Slash Commands (asumsi sudah ada)
    const commands = [
        {
            name: 'onduty',
            description: 'Melakukan Clock In.'
        },
        {
            name: 'offduty',
            description: 'Melakukan Clock Out.'
        }
    ];
    await client.application.commands.set(commands); 

    // 2. Update status Bot pertama kali
    updateBotActivity();

    // 3. Set interval untuk update status setiap 60 detik (60000ms)
    setInterval(updateBotActivity, 60000); 
});

// --- Logic API Clock In/Out ---
async function sendDutyAction(interaction, action) {
    const API_URL = process.env.API_ENDPOINT_URL;
    // ... (Logic sendDutyAction yang sudah ada)
    
    try {
        await interaction.deferReply({ ephemeral: true });

        const payload = {
            api_key: API_SECRET_KEY,
            discord_id: interaction.user.id,
            action: action 
        };

        const response = await axios.post(API_URL, payload);
        const data = response.data;
        
        let replyMessage = ``;

        if (data.status === 'success') {
            replyMessage = `✅ **${action.toUpperCase()} BERHASIL!**\nSistem mencatat: ${data.message}`;
            // PENTING: Panggil updateBotActivity setelah Clock In/Out sukses
            updateBotActivity(); 
        } else if (data.status === 'warning') {
            replyMessage = `⚠️ **PERINGATAN:** ${data.message}`;
        } else {
             replyMessage = `❌ **GAGAL EKSEKUSI:** ${data.message}\nPastikan Discord ID Anda benar dan sudah terdaftar.`;
        }

        await interaction.editReply(replyMessage);

    } catch (error) {
        console.error('API Call Error:', error.message);
        interaction.editReply(`❌ Terjadi Kesalahan Server API. Cek log Bot. Error: ${error.message}`);
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


// Login Bot menggunakan token dari Environment Variable
client.login(process.env.DISCORD_BOT_TOKEN);