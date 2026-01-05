<?php
return [
  "site_nome" => "Sorteio da Saveiro do Magrão",
  "preco_centavos" => 100, // R$ 100,00
  "min_num" => 0,
  "max_num" => 1000,
  "reserva_minutos" => 30,
  "premio_descricao" => "Saveiro surf ano 2015 1.6",
  "premio_valor" => "R$ 100,00",
  "whatsapp_numero" => "5511999999999",
  "sorteio_quantidade" => 1,
  "sorteio_senha" => "9899",
  "sorteio_hora" => "19:00",
  "fogos_intensidade" => 2,
  "fogos_intervalo_ms" => 1200,
  "confete_intervalo_ms" => 1000,
  "numero_da_sorte_ativo" => false,
  "numero_da_sorte" => [
    9 => 80,
    23 => 80,
    41 => 80,
    58 => 80,
    77 => 80,
    101 => 80,
    222 => 80,
    333 => 80,
    444 => 80
  ],
  "limpar_ganhador_ativo" => false,
  "limpar_ganhador_senha" => "4321",
  "limpar_ganhador_auto_dias" => 7,

  // PIX (payload + QR)
  "pix_chave"  => "SUA_CHAVE_PIX_AQUI",  // EVP/email/telefone/CNPJ
  "pix_nome"   => "SAVEIRO DO MAGRAO",   // até 25 chars (sem acento)
  "pix_cidade" => "CURITIBA",            // até 15 chars
  "pix_desc"   => "Sorteio Saveiro do Magrao",
  "txid_prefix"=> "SM",

  // Admin (pagamento manual enquanto não tem gateway)
  "admin_pin" => "1234",

  // Webhook (quando tiver gateway)
  "webhook_secret" => "TROQUE-ESSE-SEGREDO"
];
