// Router tuning + world catalog, formerly config/eqrouter.php. Values only; see that file's
// comments (kept until Phase 5) for the rationale behind each knob. Build-time-only keys
// (zone_point_hubs, boat_links, continent_anchors, kos_min_spawns, import tables) stay in PHP.

// Dijkstra edge cost per type — the "fastest" profile.
export const weights = {
    walk: 10,
    boat: 8,
    book: 2,
    spire: 2,
    teleport: 1,
    translocate: 1,
    evac: 1
};

// "Safest" profile: overland running is heavily penalised so boats/ports/books win.
export const weightsSafe = {
    walk: 60,
    boat: 8,
    book: 2,
    spire: 2,
    teleport: 1,
    translocate: 1,
    evac: 1
};

// UI code → label + EQEmu id. Faction modifier keys are r<id>/c<id>/d<id>.
export const races = {
    HUM: {
        label: "Human",
        id: 1
    },
    BAR: {
        label: "Barbarian",
        id: 2
    },
    ERU: {
        label: "Erudite",
        id: 3
    },
    ELF: {
        label: "Wood Elf",
        id: 4
    },
    HIE: {
        label: "High Elf",
        id: 5
    },
    DEF: {
        label: "Dark Elf",
        id: 6
    },
    HEF: {
        label: "Half Elf",
        id: 7
    },
    DWF: {
        label: "Dwarf",
        id: 8
    },
    TRL: {
        label: "Troll",
        id: 9
    },
    OGR: {
        label: "Ogre",
        id: 10
    },
    HFL: {
        label: "Halfling",
        id: 11
    },
    GNM: {
        label: "Gnome",
        id: 12
    },
    IKS: {
        label: "Iksar",
        id: 128
    },
    VAH: {
        label: "Vah Shir",
        id: 130
    },
    FRG: {
        label: "Froglok",
        id: 330
    },
    DRK: {
        label: "Drakkin",
        id: 522
    }
};

export const classes = {
    WAR: {
        label: "Warrior",
        id: 1
    },
    CLR: {
        label: "Cleric",
        id: 2
    },
    PAL: {
        label: "Paladin",
        id: 3
    },
    RNG: {
        label: "Ranger",
        id: 4
    },
    SHD: {
        label: "Shadowknight",
        id: 5
    },
    DRU: {
        label: "Druid",
        id: 6,
        port: true
    },
    MNK: {
        label: "Monk",
        id: 7
    },
    BRD: {
        label: "Bard",
        id: 8
    },
    ROG: {
        label: "Rogue",
        id: 9
    },
    SHM: {
        label: "Shaman",
        id: 10
    },
    NEC: {
        label: "Necromancer",
        id: 11
    },
    WIZ: {
        label: "Wizard",
        id: 12,
        port: true
    },
    MAG: {
        label: "Magician",
        id: 13
    },
    ENC: {
        label: "Enchanter",
        id: 14
    },
    BST: {
        label: "Beastlord",
        id: 15
    },
    BER: {
        label: "Berserker",
        id: 16
    }
};

// Agnostic (140) carries no faction modifiers.
export const deities = {
    AGNOSTIC: {
        label: "Agnostic",
        id: 140
    },
    BERTOXXULOUS: {
        label: "Bertoxxulous",
        id: 201
    },
    BRELL: {
        label: "Brell Serilis",
        id: 202
    },
    CAZICTHULE: {
        label: "Cazic-Thule",
        id: 203
    },
    EROLLISI: {
        label: "Erollisi Marr",
        id: 204
    },
    BRISTLEBANE: {
        label: "Bristlebane",
        id: 205
    },
    INNORUUK: {
        label: "Innoruuk",
        id: 206
    },
    KARANA: {
        label: "Karana",
        id: 207
    },
    MITHANIEL: {
        label: "Mithaniel Marr",
        id: 208
    },
    PREXUS: {
        label: "Prexus",
        id: 209
    },
    QUELLIOUS: {
        label: "Quellious",
        id: 210
    },
    RALLOS: {
        label: "Rallos Zek",
        id: 211
    },
    RODCET: {
        label: "Rodcet Nife",
        id: 212
    },
    SOLUSEK: {
        label: "Solusek Ro",
        id: 213
    },
    TRIBUNAL: {
        label: "The Tribunal",
        id: 214
    },
    TUNARE: {
        label: "Tunare",
        id: 215
    },
    VEESHAN: {
        label: "Veeshan",
        id: 216
    }
};

export const kosThreshold = -750;
export const kosPenalty = 200;
export const dangerMargin = 5;
export const dangerPerLevel = 8;
export const maxLevel = 60;

// Expansions at or past this ordinal have best-effort connectivity and no KOS data.
export const limitedDataExpansion = 20;

// EQEmu expansion ordinals. 99 = special/instanced content.
export const expansions = {
    "0": "Classic",
    "1": "Ruins of Kunark",
    "2": "Scars of Velious",
    "3": "Shadows of Luclin",
    "4": "Planes of Power",
    "5": "Legacy of Ykesha",
    "6": "Lost Dungeons of Norrath",
    "7": "Gates of Discord",
    "8": "Omens of War",
    "9": "Dragons of Norrath",
    "10": "Depths of Darkhollow",
    "11": "Prophecy of Ro",
    "12": "The Serpent's Spine",
    "13": "The Buried Sea",
    "14": "Secrets of Faydwer",
    "15": "Seeds of Destruction",
    "16": "Underfoot",
    "17": "House of Thule",
    "18": "Veil of Alaris",
    "19": "Rain of Fear",
    "20": "Call of the Forsaken",
    "21": "The Darkened Sea",
    "22": "The Broken Mirror",
    "23": "Empires of Kunark",
    "24": "Ring of Scale",
    "25": "The Burning Lands",
    "26": "Torment of Velious",
    "27": "Claws of Veeshan",
    "28": "Terror of Luclin",
    "29": "Night of Shadows",
    "30": "Laurion's Song",
    "31": "The Outer Brood",
    "32": "Shattering of Ro",
    "99": "Special"
};

// Server profiles. `expansions: null` = caller picks (generic TLP). `unlocks` = ordinal → unlock
// date (Y-m-d), resolved against today's date by serverExpansions(). Add a server here — no code.
export const servers = {
    live: {
        label: "Live",
        expansions: [
            0,
            1,
            2,
            3,
            4,
            5,
            6,
            7,
            8,
            9,
            10,
            11,
            12,
            13,
            14,
            15,
            16,
            17,
            18,
            19,
            20,
            21,
            22,
            23,
            24,
            25,
            26,
            27,
            28,
            29,
            30,
            31,
            32,
            99
        ],
        deltas: []
    },
    p99: {
        label: "Project 1999 (Velious)",
        expansions: [
            0,
            1,
            2
        ],
        deltas: [
            "p99"
        ]
    },
    legends: {
        label: "EverQuest Legends",
        expansions: [
            0
        ],
        deltas: [
            "legends"
        ],
        rituals: [
            "DRU",
            "WIZ"
        ]
    },
    frostreaver: {
        label: "Frostreaver (TLP)",
        unlocks: {
            1: "2026-05-27",
            2: "2026-05-27",
            3: "2026-10-01",
            4: "2027-02-04",
            5: "2027-02-04",
            6: "2027-04-01",
            7: "2027-04-29",
            8: "2027-06-24",
            9: "2027-06-24",
            10: "2027-08-19",
            11: "2027-08-19",
            12: "2027-10-14",
            13: "2027-12-09",
            14: "2028-02-03",
            15: "2028-03-30",
            16: "2028-05-25",
            17: "2028-07-20",
            18: "2028-09-14",
            19: "2028-11-09",
            20: "2029-01-04",
            21: "2029-03-01",
            22: "2029-04-26",
            23: "2029-06-21",
            24: "2029-08-16",
            25: "2029-10-11",
            26: "2029-12-06",
            27: "2030-01-31",
            28: "2030-03-28",
            29: "2030-05-23",
            30: "2030-07-18",
            31: "2030-09-12",
            32: "2030-11-07"
        },
        deltas: []
    },
    fangbreaker: {
        label: "Fangbreaker",
        group: "tlp",
        unlocks: {
            1: "2025-05-28",
            2: "2025-05-28",
            3: "2025-05-28",
            4: "2026-02-05",
            5: "2026-02-05",
            6: "2026-04-30",
            7: "2026-05-28",
            8: "2026-07-23",
            9: "2026-07-23",
            10: "2026-10-15",
            11: "2026-12-10",
            12: "2027-02-04",
            13: "2027-04-29",
            14: "2027-06-24",
            15: "2027-09-16",
            16: "2027-12-09",
            17: "2028-02-03",
            18: "2028-04-27",
            19: "2028-07-20",
            20: "2028-10-12",
            21: "2028-12-07",
            22: "2029-03-01",
            23: "2029-04-26",
            24: "2029-06-21",
            25: "2029-09-13",
            26: "2029-11-08",
            27: "2030-01-31",
            28: "2030-03-28",
            29: "2030-06-20",
            30: "2030-08-15",
            31: "2030-11-07"
        },
        deltas: []
    },
    teek: {
        label: "Teek (Random Loot)",
        group: "tlp",
        unlocks: {
            1: "2024-05-22",
            2: "2024-08-15",
            3: "2024-10-10",
            4: "2024-12-05",
            5: "2024-12-05",
            6: "2025-02-27",
            7: "2025-03-27",
            8: "2025-05-22",
            9: "2025-05-22",
            10: "2025-08-14",
            11: "2025-10-09",
            12: "2025-12-04",
            13: "2026-02-26",
            14: "2026-04-23",
            15: "2026-07-16"
        },
        deltas: []
    },
    tormax: {
        label: "Tormax",
        group: "tlp",
        unlocks: {
            1: "2024-05-22",
            2: "2024-08-15",
            3: "2024-10-10",
            4: "2024-12-05",
            5: "2024-12-05",
            6: "2025-02-27",
            7: "2025-03-27",
            8: "2025-05-22",
            9: "2025-05-22",
            10: "2025-08-14",
            11: "2025-10-09",
            12: "2025-12-04",
            13: "2026-02-26",
            14: "2026-04-23",
            15: "2026-07-16"
        },
        deltas: []
    },
    oakwynd: {
        label: "Oakwynd",
        group: "tlp",
        unlocks: {
            1: "2023-07-20",
            2: "2023-09-14",
            3: "2023-11-09",
            4: "2024-01-04",
            5: "2024-01-04",
            6: "2024-03-28",
            7: "2024-04-25",
            8: "2024-06-20",
            9: "2024-06-20",
            10: "2024-09-12",
            11: "2024-11-07",
            12: "2025-01-02",
            13: "2025-03-27",
            14: "2025-05-22",
            15: "2025-08-14",
            16: "2025-11-06",
            17: "2026-01-01",
            18: "2026-03-26",
            19: "2026-06-18",
            20: "2026-09-10",
            21: "2026-11-05",
            22: "2027-01-28",
            23: "2027-03-25",
            24: "2027-05-20",
            25: "2027-08-12",
            26: "2027-10-07",
            27: "2027-12-30",
            28: "2028-02-24",
            29: "2028-05-18"
        },
        deltas: []
    },
    yelinak: {
        label: "Yelinak",
        group: "tlp",
        unlocks: {
            1: "2022-07-20",
            2: "2022-09-14",
            3: "2022-11-09",
            4: "2023-01-05",
            5: "2023-01-05",
            6: "2023-03-30",
            7: "2023-04-27",
            8: "2023-06-22",
            9: "2023-09-14",
            10: "2023-11-09",
            11: "2024-01-04",
            12: "2024-02-29",
            13: "2024-05-23",
            14: "2024-07-18",
            15: "2024-10-10",
            16: "2025-01-02",
            17: "2025-02-27",
            18: "2025-05-22",
            19: "2025-08-14",
            20: "2025-11-06",
            21: "2026-01-01",
            22: "2026-03-26",
            23: "2026-05-21",
            24: "2026-07-16",
            25: "2026-10-08",
            26: "2026-12-03",
            27: "2027-02-25",
            28: "2027-04-22"
        },
        deltas: []
    },
    vaniki: {
        label: "Vaniki (Level-Locked)",
        group: "tlp",
        expansions: [
            0,
            1,
            2,
            3,
            4,
            5,
            6,
            7,
            8,
            9,
            10,
            11,
            12,
            13,
            14,
            15,
            16,
            17,
            18,
            19,
            20,
            21,
            22,
            23,
            24,
            25,
            26,
            27,
            28,
            29,
            30,
            31,
            32
        ],
        deltas: []
    },
    mischief: {
        label: "Mischief (Random Loot)",
        group: "tlp",
        unlocks: {
            1: "2021-06-23",
            2: "2021-08-18",
            3: "2021-10-13",
            4: "2021-12-08",
            5: "2021-12-08",
            6: "2022-03-02",
            7: "2022-03-30",
            8: "2022-05-25",
            9: "2022-08-17",
            10: "2022-10-13",
            11: "2022-12-08",
            12: "2023-02-02",
            13: "2023-04-27",
            14: "2023-06-22",
            15: "2023-09-14",
            16: "2023-12-07",
            17: "2024-02-01",
            18: "2024-04-25",
            19: "2024-07-18",
            20: "2024-10-10",
            21: "2024-12-05",
            22: "2025-02-27",
            23: "2025-04-24",
            24: "2025-06-19",
            25: "2025-09-11",
            26: "2025-11-06",
            27: "2026-01-29",
            28: "2026-03-26",
            29: "2026-06-18",
            30: "2026-08-13",
            31: "2026-11-05",
            32: "2027-01-01"
        },
        deltas: []
    },
    aradune: {
        label: "Aradune",
        group: "tlp",
        unlocks: {
            1: "2020-08-19",
            2: "2020-11-11",
            3: "2021-02-03",
            4: "2021-04-28",
            5: "2021-04-28",
            6: "2021-07-21",
            7: "2021-09-15",
            8: "2021-11-10",
            9: "2022-02-02",
            10: "2022-03-30",
            11: "2022-06-01",
            12: "2022-07-20",
            13: "2022-10-12",
            14: "2022-12-07",
            15: "2023-03-02",
            16: "2023-05-25",
            17: "2023-07-20",
            18: "2023-10-12",
            19: "2024-01-04",
            20: "2024-03-28",
            21: "2024-05-23",
            22: "2024-08-15",
            23: "2024-10-10",
            24: "2024-12-05",
            25: "2025-02-27",
            26: "2025-04-24",
            27: "2025-07-17",
            28: "2025-09-11",
            29: "2025-12-04",
            30: "2026-01-29"
        },
        deltas: []
    },
    mangler: {
        label: "Mangler",
        group: "tlp",
        expansions: [
            0,
            1,
            2,
            3,
            4,
            5,
            6,
            7,
            8,
            9,
            10,
            11,
            12,
            13,
            14,
            15,
            16,
            17,
            18,
            19,
            20,
            21,
            22,
            23,
            24,
            25,
            26,
            27,
            28,
            29,
            30,
            31,
            32
        ],
        deltas: []
    },
    agnarr: {
        label: "Agnarr (PoP-locked)",
        group: "tlp",
        expansions: [
            0,
            1,
            2,
            3,
            4,
            5,
            6
        ],
        deltas: []
    },
    tlp: {
        label: "TLP / Custom (choose expansions)",
        expansions: null,
        deltas: []
    }
};

export const defaultServer = "legends";
