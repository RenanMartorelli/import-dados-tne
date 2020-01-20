<?php

include_once 'connection.php';
$nomeCsv;
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (isset($argc)) {
	for ($i = 0; $i < $argc; $i++) {
		$nomeCsv = $argv[1];
	}
}
else {
	echo "argc and argv disabled\n";
}

$primeiraLinha = true;
$numLinha = 1;
if (($handle = fopen($nomeCsv, "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
        if($numLinha == 1){
            $numLinha ++;
            continue;
        }
        $data = array_map("utf8_encode", $data); //added
        $data = trataDados($data);
        $num = count($data);
        echo  "\n Próximo contato \n";
        $idPessoa = verificaPessoaExiste($data[1]);
        if($idPessoa == 0){
            $idPessoa = adicionaPessoa($data);
        } 

        adicionaEmail($data[5], $idPessoa);
        adicionaTelefone($data[4], $idPessoa);
        registraPresencaEvento($data[11], $idPessoa, $data[16]);
        $idInstituicao = verificaInstituicaoExiste($data[9], $data[8]);
        if($idInstituicao == 0){
            $idInstituicao = adicionaInstituicao($data[9], $data[8], $data[7]);
        }
        registraAtividades($idInstituicao, $idPessoa, $data[10]);
       
        $numLinha++;
    }
    fclose($handle);
}

function trataDados($data){
    $data[1] = strtoupper($data[1]);
    $data[2] = strtoupper($data[2]);
    $data[6] = strtoupper($data[6]);
    $data[9] = strtoupper($data[9]);

    return $data;
}


function verificaPessoaExiste($nomeCompleto){

    $conn = $GLOBALS["conn"];

    $sql = "SELECT nome, id FROM pessoas";
    $result = $conn->query($sql);


    if ($result->num_rows > 0) {

        while($row = $result->fetch_assoc()) {
            // if($GLOBALS["numLinha"] == 3) die(var_dump( $nomeCompleto . " vs " . $row["nome"]));
            if($row["nome"] == $nomeCompleto){
                return $row["id"];
            } else {
                similar_text($nomeCompleto, $row["nome"], $perc);
                if($perc > 80.00){
                    echo "O nome $nomeCompleto da planilha é análogo ao nome " . $row["nome"] . " que já existe no banco de dados. \n \n são a mesma pessoa? (s/n) \n";
                    $handle = fopen ("php://stdin","r");
                    $line = fgets($handle);
                    if(trim($line) == 's' || trim($line) == 'S' || trim($line) == 'sim' || trim($line) == 'Sim' || trim($line) == 'SIM'){
                        return $row["id"];
                    }
                    fclose($handle);
                }
                else continue;
            } 
        }
        return 0;
    } else return 0;
}

function verificaEventoExiste($codEvento){

    $conn = $GLOBALS["conn"];
    $sql = "SELECT * FROM eventos WHERE eventos.cod_evento = '$codEvento'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            return $row["id"];
        }
    } else {
        echo "Não foi encontrado registro do código do evento \n";
        return 0;
    }
}

function verificaInstituicaoExiste($nomeInstituicao, $cidadeInstituicao){

    $conn = $GLOBALS["conn"];
    $sql = "SELECT instituicoes.id FROM instituicoes
     JOIN enderecos ON enderecos.id = instituicoes.id_endereco
     JOIN cidades ON cidades.id = enderecos.id_cidade
     WHERE instituicoes.nome = '$nomeInstituicao' AND cidades.nome  = '$cidadeInstituicao'";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            return $row["id"];
        }
    } else {
        echo "Não foi encontrado registro da instituição $nomeInstituicao em $cidadeInstituicao \n";
        return 0;
    }
}

function buscaIdCidade($cidadeInstituicao, $estadoInstituicao){
    $conn = $GLOBALS["conn"];
    $sql = "SELECT cidades.id FROM cidades
    JOIN estados on estados.id = cidades.id_uf
    WHERE cidades.nome = '$cidadeInstituicao' AND estados.sigla = '$estadoInstituicao'";

   $result = $conn->query($sql);
   if ($result->num_rows == 0) {
        erroSQL($sql, $conn);
        echo "Cidade $cidadeInstituicao não existe no banco de dados";
   }

  

   while($row = $result->fetch_assoc()) {
        return $row["id"];
    }
}

function adicionaInstituicao($nomeInstituicao, $cidadeInstituicao, $estadoInstituicao){

    $idCidade = buscaIdCidade($cidadeInstituicao, $estadoInstituicao);
    
    $conn = $GLOBALS["conn"];
    // aqui, todas as casas espíritas da mesma cidade são associadas ao mesmo endereço
    // um segundo script deve registrar mais adequadamente o endereço das casas espíritas

    $sql = "SELECT id FROM enderecos WHERE id_cidade = $idCidade;";
   $result = $conn->query($sql);
   if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $idEndereco = $row["id"];
        } 
   } else {

        $sql = "INSERT INTO `sistema_tne`.`enderecos`
        (`id_cidade`)
        VALUES
        ($idCidade);";
        if ($conn->query($sql) === FALSE) {
            erroSQL($sql, $conn); 
        } else {
            $idEndereco = $conn->insert_id;
        }
   }

   $sql = "SELECT id FROM instituicoes WHERE nome = '$nomeInstituicao' AND id_endereco = $idEndereco;";
   $result = $conn->query($sql);
   if ($result->num_rows > 0) {
        echo $nomeInstituicao ." já foi registrada. \n";
   } else {
        $sql = "INSERT INTO `sistema_tne`.`instituicoes`
        (`nome`,
        `id_tipo_instituicao`,
        `id_endereco`)
        VALUES
        ('$nomeInstituicao',
        2,
        $idEndereco);";

        if ($conn->query($sql) === TRUE) {
            echo $nomeInstituicao ." registro adicionado com sucesso! \n";
            return $conn->insert_id;
        } else {
            erroSQL($sql, $conn);
        }
    }
}

function registraAtividades($idInstituicao, $idPessoa, $atividades){

    $conn = $GLOBALS["conn"];

    $tiposAtividade = [];
    
    $sql = "SELECT id, atividade FROM tipos_atividade;";
    $result = $conn->query($sql);

    $i = 0;
    while($row = $result->fetch_assoc()) {
        $tiposAtividade[$i] = array('id' => $row["id"], 'atividade' => $row["atividade"]);
        $i++;
    }

    

    $atividades = explode(", ",$atividades);
    $idTipoAtividade = "";


     foreach ($atividades as $atividade) {
        $idTipoAtividade = null;
        foreach ($tiposAtividade as $tipoAtividade) {
            if($atividade == $tipoAtividade['atividade']){
                $idTipoAtividade = $tipoAtividade['id'];
                break;
            }
        }

        if($idTipoAtividade == null){
            echo "A atividade $atividade na linha " . $GLOBALS['numLinha'] . "Não consta no banco de dados \n";
            continue;
        }

        $sql = "SELECT id FROM atividades_instituicoes WHERE
         id_pessoa = $idPessoa
         AND id_tipo_atividade = $idTipoAtividade 
         AND id_instituicao = $idInstituicao;";

        $result = $conn->query($sql);
        if ($result->num_rows > 0) {
            echo "Este registro de atividade já existe! \n";
            continue;
        }  


        $sql = "INSERT INTO `sistema_tne`.`atividades_instituicoes`
        (`id_instituicao`,
        `id_pessoa`,
        `id_tipo_atividade`)
        VALUES
        ($idInstituicao,
        $idPessoa,
        $idTipoAtividade);";
    
        if ($conn->query($sql) === TRUE) {
            echo "Registro de atividade adicionado com sucesso! \n";
        } else {
            erroSQL($sql, $conn);
        }
    }
}

function adicionaPessoa($data){

    $conn = $GLOBALS["conn"];
    $sql = "INSERT INTO `sistema_tne`.`pessoas`
    (`nome`,
    `apelido`,
    `sexo`,
    `cidade`,
    `estado`)
    VALUES
    ('$data[1]',
    '$data[2]',
    '$data[3]',
    '$data[6]',
    '$data[7]');";

    if ($conn->query($sql) === TRUE) {
        echo $data[1] ." registro adicionado com sucesso! \n";
        return $conn->insert_id;
    } else {
        erroSQL($sql, $conn);
    }
}

function adicionaTelefone($telefone, $idPessoa){

    $conn = $GLOBALS["conn"];

    if ($telefone == '') {
        echo "O campo telefone está vazio. \n";
        return;
    }

    $sql = "SELECT * FROM telefones WHERE telefones.numero = '$telefone'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        echo "O telefone " . $telefone . " já existe nos registros. \n";
        return;
    }

    $sql = "INSERT INTO `sistema_tne`.`telefones`
    (`tipo`,
    `numero`,
    `id_pessoa`)
    VALUES
    ('Movel',
    '$telefone',
    '$idPessoa');";

    if ($conn->query($sql) === TRUE) {
        echo "Telefone adicionado com sucesso \n";
    } else {
        erroSQL($sql, $conn);
    }
}

function adicionaEmail($email, $idPessoa){

    $conn = $GLOBALS["conn"];

    if ($email == '') {
        echo "O campo email está vazio. \n";
        return;
    }

    $sql = "SELECT * FROM emails WHERE emails.email = '$email'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        echo "O email " . $email . " já existe nos registros. \n";
        return;
    }

    $sql = "INSERT INTO `sistema_tne`.`emails`
    (`email`,
    `id_pessoa`)
    VALUES
    ('$email',
    '$idPessoa');";

    if ($conn->query($sql) === TRUE) {
        echo "Email adicionado com sucesso \n";
    } else {
        erroSQL($sql, $conn);
    }
}

function registraPresencaEvento($codEvento, $idPessoa, $comoFicouSabendoDoEvento){

    $conn = $GLOBALS["conn"];

    if ($codEvento == '') {
        echo "O campo código_evento está vazio. \n";
        return;
    }

    $idEvento = verificaEventoExiste($codEvento);
    if($idEvento == 0){
        echo "Não existe evento com o código do evento $codEvento . \n";
        return;
    }


    $sql = "SELECT * FROM presencas_eventos WHERE presencas_eventos.id_evento = '$idEvento' AND presencas_eventos.id_pessoa = '$idPessoa'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        echo "Essa presença já foi registrada. \n";
        return;
    }

    $sql = "INSERT INTO `sistema_tne`.`presencas_eventos`
    (`id_evento`,
    `como_ficou_sabendo_do_evento`,
    `id_pessoa`)
    VALUES
    ('$idEvento',
    '$comoFicouSabendoDoEvento',
    '$idPessoa');";

    if ($conn->query($sql) === TRUE) {
        echo "Presença registrada com sucesso \n";
    } else {
        erroSQL($sql, $conn);
    }
}

function erroSQL($sql, $conn){
    echo "Error: " .  $sql . "<br>" . $conn->error;
}
