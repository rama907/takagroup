// File: DiscordBot/Elysium/index.js

const { Client, GatewayIntentBits, ActivityType, ActionRowBuilder, ButtonBuilder, ButtonStyle, ComponentType } = require('discord.js');
const axios = require('axios');
require('dotenv').config();

// --- Konfigurasi Azure Environment Variables ---
const API_SECRET_KEY = process.env.API_SECRET_KEY;
const API_ENDPOINT_URL = process.env.API_ENDPOINT_URL; 

// FIX: Definisikan URL turunan dengan aman menggunakan ternary operator
const STATUS_URL_DERIVATION = API_ENDPOINT_URL 
    ? API_ENDPOINT_URL.replace('duty_action.php', 'get_duty_status.php') 
    : undefined;

const MY_STATUS_URL_DERIVATION = API_ENDPOINT_URL 
    ? API_ENDPOINT_URL.replace('duty_action.php', 'get_my_status.php') 
    : undefined;

const PENDING_REQUESTS_URL_DERIVATION = API_ENDPOINT_URL 
    ? API_ENDPOINT_URL.replace('duty_action.php', 'get_pending_requests.php') 
    : undefined;

const PROCESS_ACTION_URL_DERIVATION = API_ENDPOINT_URL 
    ? API_ENDPOINT_URL.replace('duty_action.php', 'process_request_action.php') 
    : undefined;

// Inisialisasi variabel dengan nilai yang diturunkan atau undefined jika BASE URL hilang
const STATUS_API_URL = process.env.STATUS_API_URL || STATUS_URL_DERIVATION;
const MY_STATUS_API_URL = process.env.MY_STATUS_API_URL || MY_STATUS_URL_DERIVATION;
const API_GET_PENDING_REQUESTS_URL = process.env.API_GET_PENDING_REQUESTS_URL || PENDING_REQUESTS_URL_DERIVATION;
const API_PROCESS_REQUEST_ACTION_URL = process.env.API_PROCESS_REQUEST_ACTION_URL || PROCESS_ACTION_URL_DERIVATION;
// END NEW URLS


const client = new Client({
    intents: [
        GatewayIntentBits.Guilds,
        GatewayIntentBits.GuildMessages,
        GatewayIntentBits.MessageContent,
        GatewayIntentBits.GuildIntegrations
    ]
});


// --- FUNGSI KRITIS: Mengambil status dan mengupdate Bot Presence ---
async function updateBotActivity() {
    // FIX PENTING: Menambahkan pemeriksaan STATUS_API_URL
    if (!STATUS_API_URL) {
        console.error('[PRESENCE] Gagal memanggil API Status: STATUS_API_URL is not defined or API_ENDPOINT_URL is missing.');
        client.user.setActivity('Config Error', { type: ActivityType.Watching });
        return;
    }
    
    try {
        const response = await axios.get(STATUS_API_URL);
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
client.once('clientReady', async () => {
    console.log(`Bot Siap! Logged in as ${client.user.tag}`);
    
    // 1. Daftarkan Slash Commands 
    const commands = [
        {
            name: 'onduty',
            description: 'Melakukan Clock In.'
        },
        {
            name: 'offduty',
            description: 'Melakukan Clock Out.'
        },
        { 
            name: 'statusku',
            description: 'Menampilkan status duty, jam kerja, dan total penjualan Anda.'
        },
        { // NEW MANAGER COMMAND
            name: 'kelola_permohonan',
            description: 'Menampilkan semua permohonan pending dengan tombol interaktif.'
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

// --- Logic API Statusku ---
async function sendStatusQuery(interaction) {
    const API_URL = MY_STATUS_API_URL;

    try {
        await interaction.deferReply({ ephemeral: true });

        const payload = {
            api_key: API_SECRET_KEY,
            discord_id: interaction.user.id,
        };

        const response = await axios.post(API_URL, payload);
        const data = response.data.data;
        
        let replyEmbed;
        let color;

        if (response.data.status === 'success') {
            const dutyStatus = data.is_on_duty ? '🟢 ON DUTY' : '🔴 OFF DUTY';
            const currentDuration = data.is_on_duty ? data.current_duty_duration : 'N/A';
            color = data.is_on_duty ? 3066993 : 15158332;

            replyEmbed = {
                title: `📊 Status Aktivitas ${data.employee_name}`,
                description: `Halo, **${data.employee_name}**! Berikut ringkasan performa Anda.`,
                color: color,
                fields: [
                    {
                        name: 'Status Tugas Saat Ini',
                        value: dutyStatus,
                        inline: false,
                    },
                    {
                        name: 'Durasi On Duty (Saat Ini)',
                        value: currentDuration,
                        inline: true,
                    },
                    {
                        name: 'Total Jam Kerja (Keseluruhan)',
                        value: data.total_duty_overall,
                        inline: true,
                    },
                    {
                        name: 'Total Penjualan Paket (Keseluruhan)',
                        value: `${data.total_sales_overall} Paket`,
                        inline: true,
                    },
                ],
                footer: {
                    text: 'Data diambil dari Elysium Night Club Management System',
                },
                timestamp: new Date().toISOString(),
            };

        } else {
             replyEmbed = {
                title: '❌ Gagal Mengambil Status',
                description: `Pesan: ${response.data.message}`,
                color: 15158332,
                footer: {
                    text: 'Pastikan Discord ID Anda terdaftar pada karyawan aktif.',
                },
            };
        }

        await interaction.editReply({ embeds: [replyEmbed] });

    } catch (error) {
        console.error('API Call Error (Statusku):', error.message);
        interaction.editReply(`❌ Terjadi Kesalahan Server API saat mengambil status. Cek log Bot. Error: ${error.message}`);
    }
}


// --- NEW: Logic Permohonan Interaktif ---

async function handleRequestManagement(interaction) {
    // Check if user has admin/director role (simple check, full check is on PHP API side)
    // IMPORTANT: Replace with actual Discord role names used for Manajer/Direktur permissions
    const member = interaction.guild.members.cache.get(interaction.user.id);
    const hasPermission = member.roles.cache.some(role => 
        ['CEO', 'DIREKTUR', 'WAKIL DIREKTUR', 'MANAGER'].includes(role.name) // Ganti dengan nama role Discord yang sebenarnya
    );

    if (!hasPermission) {
        return interaction.reply({ content: '❌ Anda tidak memiliki izin untuk menggunakan perintah ini.', ephemeral: true });
    }

    try {
        await interaction.deferReply({ ephemeral: true });

        const payload = {
            api_key: API_SECRET_KEY,
        };

        const response = await axios.post(API_GET_PENDING_REQUESTS_URL, payload);
        const requests = response.data.data;
        
        if (response.data.status !== 'success' || requests.length === 0) {
            return interaction.editReply({ 
                content: '✅ Tidak ada permohonan pending saat ini.', 
                ephemeral: true 
            });
        }
        
        let messageComponents = [];
        let messageContent = `📋 **${requests.length} Permohonan Pending** | Kelola Sekarang:\n`;

        requests.forEach((req, index) => {
            // Build the list content
            messageContent += `\n**[#${req.id}] ${req.display_type}** dari **${req.employee_name}**\n\`Detail: ${req.details}\`\n`;

            // Create buttons for this request
            const row = new ActionRowBuilder()
                .addComponents(
                    new ButtonBuilder()
                        .setCustomId(`approve_${req.type}_${req.id}`)
                        .setLabel(`✅ Setujui #${req.id}`)
                        .setStyle(ButtonStyle.Success),
                    new ButtonBuilder()
                        .setCustomId(`reject_${req.type}_${req.id}`)
                        .setLabel(`❌ Tolak #${req.id}`)
                        .setStyle(ButtonStyle.Danger),
                );
            
            messageComponents.push(row);
        });
        
        return interaction.editReply({ 
            content: messageContent,
            components: messageComponents,
            ephemeral: true
        });

    } catch (error) {
        console.error('API Call Error (Permohonan):', error.message);
        return interaction.editReply(`❌ Terjadi Kesalahan Server API saat mengambil permohonan. Error: ${error.message}`);
    }
}

// --- NEW: Handler for Button Clicks ---

async function handleRequestButton(interaction) {
    if (!interaction.isButton()) return;
    
    // Permission check for added security
    const member = interaction.guild.members.cache.get(interaction.user.id);
    const hasPermission = member.roles.cache.some(role => 
        ['CEO', 'DIREKTUR', 'WAKIL DIREKTUR', 'MANAGER'].includes(role.name)
    );

    if (!hasPermission) {
        return interaction.reply({ content: '❌ Anda tidak memiliki izin untuk menyetujui/menolak permohonan.', ephemeral: true });
    }

    await interaction.deferReply({ ephemeral: true });

    const [action, type, id] = interaction.customId.split('_'); // e.g., 'approve_leave_123'
    const request_id = parseInt(id);
    
    if (!request_id || !type || (action !== 'approve' && action !== 'reject')) {
        return interaction.editReply('❌ Format tombol tidak valid.');
    }

    try {
        const payload = {
            api_key: API_SECRET_KEY,
            request_id: request_id,
            request_type: type,
            action: action,
            admin_id: interaction.user.id // Send Discord ID as admin_id
        };

        const response = await axios.post(API_PROCESS_REQUEST_ACTION_URL, payload);
        const apiResponse = response.data;
        
        if (apiResponse.status === 'success') {
            // Disable the buttons after successful action
            const disabledComponents = interaction.message.components.map(row => {
                return new ActionRowBuilder().addComponents(
                    row.components.map(button => {
                        if (button.customId.includes(`_${type}_${id}`)) {
                            return ButtonBuilder.from(button).setDisabled(true);
                        }
                        return ButtonBuilder.from(button);
                    })
                );
            });
            
            // Edit the original message content to mark as processed and show the result
            const newContent = interaction.message.content.replace(
                `[#${id}]`, 
                `[#${id}] - ${action === 'approve' ? 'DISETUJUI' : 'DITOLAK'}`
            );
            
            await interaction.editReply({ 
                content: `Berhasil memproses permintaan #${id}.`,
            });
            
            // Edit the original manager message (the one with the list) to disable the buttons
            await interaction.message.edit({ 
                content: newContent,
                components: disabledComponents.filter(row => row.components.length > 0)
            });
            
        } else {
            // API failed or item already processed
            return interaction.editReply(`⚠️ **Gagal memproses permintaan #${id}:** ${apiResponse.message}`);
        }

    } catch (error) {
        console.error('Button Action Error:', error.message);
        return interaction.editReply(`❌ Terjadi Kesalahan Server: ${error.message}`);
    }
}


// --- Logic Command Handler ---
client.on('interactionCreate', async interaction => {
    if (interaction.isCommand()) {
        if (interaction.commandName === 'onduty') {
            await sendDutyAction(interaction, 'clock_in');
        } else if (interaction.commandName === 'offduty') {
            await sendDutyAction(interaction, 'clock_out');
        } else if (interaction.commandName === 'statusku') {
            await sendStatusQuery(interaction);
        } else if (interaction.commandName === 'kelola_permohonan') {
            await handleRequestManagement(interaction);
        }
    } else if (interaction.isButton()) {
        // Handle all button clicks starting with 'approve_' or 'reject_'
        if (interaction.customId.startsWith('approve_') || interaction.customId.startsWith('reject_')) {
            await handleRequestButton(interaction);
        }
    }
});


// Login Bot menggunakan token dari Environment Variable
client.login(process.env.DISCORD_BOT_TOKEN);